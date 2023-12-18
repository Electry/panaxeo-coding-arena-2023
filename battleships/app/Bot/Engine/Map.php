<?php declare(strict_types = 1);

namespace Electry\Battleships\Bot\Engine;

use Electry\Battleships\Exceptions\DataException;
use Electry\Battleships\Exceptions\Engine\EngineException;
use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Model\Engine\Battleship;
use Electry\Battleships\Model\Engine\Enums\Rotation;
use Electry\Battleships\Model\Engine\Shape;
use Electry\Battleships\Model\Engine\Enums\Cell;
use Electry\Battleships\Model\Engine\Enums\ShapeType;
use Electry\Battleships\Responses\FireResponse;
use JsonException;
use JsonSerializable;
use Override;
use SplObjectStorage;

/**
 * Incomplete map.
 *
 * Keywords:
 *  - Battleship:
 *    - confirmed battleship / discovered battleship = the exact location of the battleship is 100% known (but it might not be destroyed yet)
 *    - destroyed battleship = all {@see Cell::SHIP} cells of the battleship are shot down (server-side)
 *  - Cell:
 *    - inferred cell = cell where the value of the cell is 100% known (but only locally, the server might still respond with {@see Cell::UNKNOWN})
 *    - original cell = cell which contains value as seen from the API call
 *  - Shape Type:
 *    - possible shape type = one of the few shape types which might be placed at a given cell/location, according to the inferred cells
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-29
 */
final class Map implements JsonSerializable
{
  /** @var int Fixed width. */
  public const int WIDTH = 12;

  /** @var int Fixed height. */
  public const int HEIGHT = 12;

  /** @var int Heat-map value for non-calculated or {@see Cell::WATER} cell. */
  public const int HEATMAP_NO_VALUE = 0;

  /** @var int Maximum heat-map value (used for marking 100% confirmed cells). */
  public const int HEATMAP_MAXIMUM_VALUE = 999999;

  /** @var array<int, array<int, Cell>> Grid: [x] => [y] => Inferred cell. */
  private array $grid;

  /** @var array<int, array<int, Cell>>> Grid: [x] => [y] => Original cell (as seen from the API). */
  private array $originalGrid;

  /** @var array<int, array<int, int|float>> Grid: [x] => [y] => Probability value. */
  private array $heatMap;

  /** @var array<int, array<int, Battleship|null>> Grid: [x] => [y] => 100% confirmed battleship (which might not be yet destroyed). */
  private array $confirmedBattleshipsGrid;

  /** @var array<int, array<int, ShapeType[]>> Grid: [x] => [y] => the only possible battleship shape types (if empty, any of the shape types are possible). */
  private array $possibleShapeTypesGrid;

  /** @var array<string, bool> [shapeType->value] => is confirmed flag (but not necessarily destroyed yet). */
  private array $shapeTypeValueToIsConfirmedMap;

  /** @var array<int, array<int, float>> Grid: [x] => [y] => Bias. */
  private static array $biasGrid;

  /** @var array<string, array<int, array<int, float>>> Grid: [x] => [y] => Shape type bias. */
  private static array $shapeTypeBiasGrid;

  /**
   * Create an empty map.
   *
   * Sets:
   *  - all inferred grid cells to {@see Cell::UNKNOWN} values.
   *  - original grid to contain the same values as the inferred grid
   *  - all confirmed battleships grid values to null (aka. no confirmed ship on any of the cells)
   *  - all possible shape type values to empty array (aka. any shape type is possible on any of the cells)
   *  - all heat-map values to 0
   */
  private function __construct()
  {
    // Reset confirmed shapes to none
    $this->shapeTypeValueToIsConfirmedMap = [];
    foreach (ShapeType::cases() as $shapeType) {
      $this->shapeTypeValueToIsConfirmedMap[$shapeType->value] = false;
    }

    // Reset all grid cells to UNKNOWN
    $this->confirmedBattleshipsGrid = [];
    $this->possibleShapeTypesGrid = [];
    $this->grid = [];
    $this->heatMap = [];

    for ($x = 0; $x < self::WIDTH; $x++) {
      $this->confirmedBattleshipsGrid[$x] = [];
      $this->possibleShapeTypesGrid[$x] = [];
      $this->grid[$x] = [];
      $this->heatMap[$x] = [];

      for ($y = 0; $y < self::HEIGHT; $y++) {
        $this->confirmedBattleshipsGrid[$x][$y] = null;
        $this->possibleShapeTypesGrid[$x][$y] = [];
        $this->grid[$x][$y] = Cell::UNKNOWN;
        $this->heatMap[$x][$y] = self::HEATMAP_NO_VALUE;
      }
    }

    // Also store the original grid as a copy for reference
    $this->originalGrid = $this->grid;
  }

  /** @inheritDoc */
  #[Override]
  public function jsonSerialize(): array
  {
    $confirmedBattleshipsGrid = [];

    // Transform confirmed battleships grid in a way that would make it easy
    // to re-use battleship objects during un-serialization
    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        $confirmedBattleship = $this->confirmedBattleshipsGrid[$x][$y];

        if ($confirmedBattleship !== null) {
          $confirmedBattleshipsGrid
            [$confirmedBattleship->shape->getShapeType()->value]
            [$confirmedBattleship->x]
            [$confirmedBattleship->y]
            [$confirmedBattleship->rotation->value] ??= [];

          $confirmedBattleshipsGrid
            [$confirmedBattleship->shape->getShapeType()->value]
            [$confirmedBattleship->x]
            [$confirmedBattleship->y]
            [$confirmedBattleship->rotation->value][] = [$x, $y];
        }
      }
    }

    return [
      'grid' => $this->grid,
      'confirmed_battleships_grid' => $confirmedBattleshipsGrid,
      'possible_shape_types_grid' => $this->possibleShapeTypesGrid,
      'shape_type_value_to_is_confirmed_map' => $this->shapeTypeValueToIsConfirmedMap
    ];
  }

  /**
   * Unserialize from json data.
   *
   * @param array{
   *   grid: array<int, array<int, string>>,
   *   confirmed_battleships_grid: array<string, array<int, array<int, array<string, array{0: int, 1: int}[]>>>>,
   *   possible_shape_types_grid: array<int, array<int, string>>,
   *   shape_type_value_to_is_confirmed_map: array<string, bool>
   * }              $data
   *
   * @return self
   * @throws FatalException
   */
  public static function fromSerializedData(array $data): self
  {
    $map = new self();

    // Transform the inferred grid & possible shape types grid
    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        $cell = Cell::from($data['grid'][$x][$y]);

        // Overwrite all cells by the inferred data
        $map->grid[$x][$y] = $cell;

        // Create enum instance from every possible shape type value
        $possibleShapeTypeValues = $data['possible_shape_types_grid'][$x][$y];
        foreach ($possibleShapeTypeValues as $possibleShapeTypeValue) {
          $map->possibleShapeTypesGrid[$x][$y][] = ShapeType::from($possibleShapeTypeValue);
        }
      }
    }

    // Transform the confirmed battleships grid (and re-use already constructed battleship objects for each cell)
    foreach ($data['confirmed_battleships_grid'] as $shapeTypeValue => $t1) {
      foreach ($t1 as $x => $t2) {
        foreach ($t2 as $y => $t3) {
          foreach ($t3 as $rotationValue => $coords) {
            $battleship = new Battleship(
              Shape::fromShapeType(ShapeType::from($shapeTypeValue)),
              $x,
              $y,
              Rotation::from($rotationValue)
            );

            foreach ($coords as [$cX, $cY]) {
              $map->confirmedBattleshipsGrid[$cX][$cY] = $battleship;
            }
          }
        }
      }
    }

    // Simple string => bool array, no need to transform it...
    $map->shapeTypeValueToIsConfirmedMap = $data['shape_type_value_to_is_confirmed_map'];

    return $map;
  }

  /**
   * Create map from a brand-new grid as seen in the response from the API.
   * This is only to be used on newly generated maps, as the response grid doesn't contain all the locally inferred cell values.
   *
   * @param string $grid Grid as seen in the {@see FireResponse}.
   *
   * @return self
   * @throws DataException
   * @throws FatalException
   */
  public static function fromFireResponseGrid(string $grid): self
  {
    if (strlen($grid) !== self::WIDTH * self::HEIGHT) {
      throw new DataException('Bad grid size');
    }

    $map = new self();
    $map->updateByFireResponseGrid($grid);

    return $map;
  }

  /**
   * Create empty map.
   *
   * @return self
   */
  public static function createEmpty(): self
  {
    return new self();
  }

  /**
   * Update ONLY the inferred & original grids by the grid string as seen in the response from the API.
   *
   * @param string $grid Grid as seen in the {@see FireResponse}.
   *
   * @return void
   * @throws DataException
   * @throws FatalException
   */
  public function updateByFireResponseGrid(string $grid): void
  {
    // Reset the original grid
    $this->originalGrid = [];

    // Update grids based on the grid string as seen in the API response
    for ($x = 0; $x < self::WIDTH; $x++) {
      $this->originalGrid[$x] = [];

      for ($y = 0; $y < self::HEIGHT; $y++) {
        $cell = Cell::from($grid[$y * self::WIDTH + $x]);

        // Update the original grid
        $this->originalGrid[$x][$y] = $cell;

        // Not yet discovered server-side, cannot update possibly discovered cell in the inferred grid!
        if ($cell === Cell::UNKNOWN) {
          continue;
        }

        // Sanity check that we didn't infer anything wrongly!
        if ($this->grid[$x][$y] !== $cell && $this->grid[$x][$y] !== Cell::UNKNOWN) {
          echo '------ EXCEPTION ------' . PHP_EOL;
          echo $this;
          echo '-----------------------' . PHP_EOL;
          echo Map::fromFireResponseGrid($grid);

          throw new FatalException('Supplied grid from an API response does not match with the previously inferred grid: API cell: ' . $cell->name . ', inferred cell: ' . $this->grid[$x][$y]->name . ', x: ' . $x . ', y: ' . $y);
        }

        $this->grid[$x][$y] = $cell;
      }
    }
  }

  /**
   * Calculate initial heat-map values based on the grid cells.
   *
   * @return void
   */
  public function initializeHeatMapByGrid(): void
  {
    $this->heatMap = [];

    for ($x = 0; $x < self::WIDTH; $x++) {
      $this->heatMap[$x] = [];

      for ($y = 0; $y < self::HEIGHT; $y++) {
        $cell = $this->grid[$x][$y];

        // If the cell is WATER, it cannot possibly be a ship, thus the value has to be 0
        if ($cell === Cell::WATER) {
          $this->heatMap[$x][$y] = self::HEATMAP_NO_VALUE;
        }
        // If the cell is SHIP, it already is a ship, set the value to the maximum value
        else if ($cell === Cell::SHIP) {
          $this->heatMap[$x][$y] = self::HEATMAP_MAXIMUM_VALUE;
        }
        // Else it must be UNKNOWN cell, set the value to non-calculated value,
        // as we will recalculate these values based on the next hit probability
        else {
          $this->heatMap[$x][$y] = self::HEATMAP_NO_VALUE;
        }
      }
    }
  }

  /**
   * Return all not destroyed shape types.
   *
   * NOTE: This might actually return a shape type that is already destroyed server-side,
   *  but we might not know it yet, since it's not 100% confirmed shape type!
   *  Hence, we need to make sure all discovered ship cells have their corresponding confirmed shape type,
   *  before using this!
   *
   * @return ShapeType[]
   * @throws FatalException
   */
  public function getAllNotDestroyedShapeTypes(): array
  {
    $result = [];

    // Obtain a map of confirmed shape types to their corresponding battleships
    $confirmedShapeTypeValueToBattleshipMap = $this->getConfirmedShapeTypeValueToBattleshipMap();

    foreach (ShapeType::cases() as $shapeType) {
      // If the shape type is not confirmed yet, it cannot possibly be destroyed yet
      if (!isset($confirmedShapeTypeValueToBattleshipMap[$shapeType->value])) {
        $result[] = $shapeType;
        continue;
      }

      $battleship = $confirmedShapeTypeValueToBattleshipMap[$shapeType->value];

      // If the shape type is confirmed, check if it is destroyed server-side
      if (!$this->isBattleshipDestroyed($battleship)) {
        $result[] = $shapeType;
      }
    }

    return $result;
  }

  /**
   * Return true if given battleship is destroyed.
   *
   * @param Battleship $battleship
   *
   * @return bool
   * @throws FatalException
   */
  public function isBattleshipDestroyed(Battleship $battleship): bool
  {
    return count($this->getAllNotDestroyedShipCellCoordinatesForBattleship($battleship)) === 0;
  }

  /**
   * Get all {@see Cell::SHIP} coordinates of a confirmed battleship which were not yet shot/destroyed
   * (aka. discovered server-side).
   *
   * @param Battleship $battleship
   *
   * @return array{0: int, 1: int}[]
   * @throws FatalException
   */
  public function getAllNotDestroyedShipCellCoordinatesForBattleship(Battleship $battleship): array
  {
    $coordinates = $battleship->getShipCellCoordinates();
    $results = [];

    foreach ($coordinates as [$x, $y]) {
      if ($this->originalGrid[$x][$y] === Cell::WATER) {
        throw new FatalException('Fatal: Original grid contains battleship cell marked as water, bad battleship supplied?');
      }

      if ($this->originalGrid[$x][$y] === Cell::UNKNOWN) {
        $results[] = [$x, $y];
      }
    }

    return $results;
  }

  /**
   * Get all not discovered (server-side, aka. {@see Cell::UNKNOWN} cells in the original grid) coordinates.
   *
   * @return array{0: int, 1: int}[]
   */
  public function getAllNotDiscoveredCoordinates(): array
  {
    $result = [];

    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        if ($this->originalGrid[$x][$y] === Cell::UNKNOWN) {
          $result[] = [$x, $y];
        }
      }
    }

    return $result;
  }

  /**
   * Return all 100% confirmed battleships.
   * (aka. location of the shape type is known but the battleship might not yet be fully destroyed server-side).
   *
   * @return array<string, Battleship> [shape type value] => battleship.
   */
  public function getConfirmedShapeTypeValueToBattleshipMap(): array
  {
    $confirmedBattleships = [];

    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        $confirmedBattleship = $this->confirmedBattleshipsGrid[$x][$y];

        // Here we're relying on the fact, that a single shape type can only be placed on the map once (as a single battleship)
        if ($confirmedBattleship !== null) {
          $confirmedBattleships[$confirmedBattleship->shape->getShapeType()->value] = $confirmedBattleship;
        }
      }
    }

    return $confirmedBattleships;
  }

  /**
   * Return true if the given shape type is 100% confirmed.
   * (aka. location of the shape type is known but the battleship might not yet be fully destroyed server-side).
   *
   * @param ShapeType $shapeType
   *
   * @return bool
   */
  public function isShapeTypeConfirmed(ShapeType $shapeType): bool
  {
    return $this->shapeTypeValueToIsConfirmedMap[$shapeType->value];
  }

  /**
   * Return all 100% confirmed shape types.
   *
   * @return ShapeType[]
   */
  public function getConfirmedShapeTypes(): array
  {
    $result = [];

    foreach (ShapeType::cases() as $shapeType) {
      if ($this->isShapeTypeConfirmed($shapeType)) {
        $result[] = $shapeType;
      }
    }

    return $result;
  }

  /**
   * Return all un-confirmed shape types.
   *
   * @return ShapeType[]
   */
  public function getUnconfirmedShapeTypes(): array
  {
    $result = [];

    foreach (ShapeType::cases() as $shapeType) {
      if (!$this->isShapeTypeConfirmed($shapeType)) {
        $result[] = $shapeType;
      }
    }

    return $result;
  }

  /**
   * Return largest unconfirmed shape type.
   *
   * @return ShapeType|null
   * @throws FatalException
   */
  public function getLargestUnconfirmedShapeType(): ?ShapeType
  {
    $largestUnconfirmedShape = null;

    foreach ($this->getUnconfirmedShapeTypes() as $shapeType) {
      $shape = Shape::fromShapeType($shapeType);
      if ($largestUnconfirmedShape === null || $shape->getOccupiedCells() > $largestUnconfirmedShape->getOccupiedCells()) {
        $largestUnconfirmedShape = $shape;
      }
    }

    return $largestUnconfirmedShape?->getShapeType();
  }

  /**
   * Recalculate all new 100% confirmed shapes based on the map rules, and place them on the inferred map.
   *
   * @return void
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function recalculateNewConfirmedShapeTypes(): void
  {
    $foundNewBattleships = null;

    while ($foundNewBattleships === null || !empty($foundNewBattleships)) {
      if ($foundNewBattleships !== null) {
        echo 'Found ' . implode(', ', array_map(static fn (Battleship $battleship) => $battleship->shape->getShapeType()->value, $foundNewBattleships))
          . ' in the previous cycle, checking again' . PHP_EOL;
      }

      $foundNewBattleships = [];

      for ($x = 0; $x < self::WIDTH; $x++) {
        for ($y = 0; $y < self::HEIGHT; $y++) {
          $cell = $this->grid[$x][$y];
          if ($cell !== Cell::SHIP) {
            continue;
          }

          // Only deal with un-confirmed cells
          if ($this->confirmedBattleshipsGrid[$x][$y] !== null) {
            continue;
          }

          /** @var array<string, Battleship[]> $possibleBattleshipsForCurrentCell [shape type value] => possible battleships for current cell. */
          $possibleBattleshipsForCurrentCell = [];

          /** @var ShapeType[] $validShapeTypesForCurrentCell */
          $validShapeTypesForCurrentCell = [];

          // If the cell is marked as a part of some shape type (most likely due to avenger ability hint), only check that shape type
          $possibleShapeTypes = $this->possibleShapeTypesGrid[$x][$y];
          if (!empty($possibleShapeTypes)) {
            $shapeTypesToCheck = $possibleShapeTypes;
          } else {
            $shapeTypesToCheck = ShapeType::cases();
          }

          // Get possible coordinates for all ship types
          foreach ($shapeTypesToCheck as $shapeType) {
            $possibleBattleshipsForCurrentCell[$shapeType->value] = $this->calculatePossibleBattleshipsForShapeTypeAndCoordinates($shapeType, $x, $y);

            // If there is at least one way of placing the shape type so that it overlaps current cell, mark it as a valid shape type
            if (count($possibleBattleshipsForCurrentCell[$shapeType->value]) > 0) {
              $validShapeTypesForCurrentCell[] = $shapeType;
            }
          }

          // If only single shape can be possibly placed so that it overlaps current cell
          // (or two in case of submarine/destroyer as they share the same layout)
          if (count($validShapeTypesForCurrentCell) === 1
            || (count($validShapeTypesForCurrentCell) === 2
              && in_array(ShapeType::SUBMARINE, $validShapeTypesForCurrentCell, true)
              && in_array(ShapeType::DESTROYER, $validShapeTypesForCurrentCell, true))) {

            // Take the valid shape type
            $shapeType = $validShapeTypesForCurrentCell[0];

            // If it's submarine or destroyer, make sure we select the unconfirmed shape of those two!
            if (in_array($shapeType, [ShapeType::SUBMARINE, ShapeType::DESTROYER], true)) {
              $submarineConfirmed = $this->isShapeTypeConfirmed(ShapeType::SUBMARINE);
              $destroyerConfirmed = $this->isShapeTypeConfirmed(ShapeType::DESTROYER);

              if ($shapeType === ShapeType::SUBMARINE && $submarineConfirmed) {
                $shapeType = ShapeType::DESTROYER;
              } else if ($shapeType === ShapeType::DESTROYER && $destroyerConfirmed) {
                $shapeType = ShapeType::SUBMARINE;
              } else if ($submarineConfirmed && $destroyerConfirmed) {
                throw new FatalException('Should never happen!');
              }
            }

            // If the single valid shape can only be placed ONE WAY, it must then 100% be the correct way
            if (count($possibleBattleshipsForCurrentCell[$shapeType->value]) === 1) {
              $battleship = $possibleBattleshipsForCurrentCell[$shapeType->value][0];

              echo 'There is only one possible way to place ' . $shapeType->value
                . ', so putting it at x: ' . $battleship->x . ', y: ' . $battleship->y . PHP_EOL;
              echo 'Confirmed shape types before placement: '
                . implode(', ', array_map(static fn(ShapeType $shapeType) => $shapeType->value, $this->getConfirmedShapeTypes())) . PHP_EOL;

              // Place the battleship on the map (and infer adjacent cells), mark it as confirmed
              $this->placeBattleship($battleship);
              $foundNewBattleships[] = $battleship;
            }
          }

          $largestUnconfirmedShapeType = $this->getLargestUnconfirmedShapeType();
          if ($largestUnconfirmedShapeType !== null && in_array($largestUnconfirmedShapeType, $shapeTypesToCheck, true)) {
            $possibleBattleshipsForCurrentCellWithoutShipCellOnUnknownCell = $this
              ->calculatePossibleBattleshipsForShapeTypeAndCoordinates($largestUnconfirmedShapeType, $x, $y, true);

            if (count($possibleBattleshipsForCurrentCellWithoutShipCellOnUnknownCell) === 1) {
              $battleship = $possibleBattleshipsForCurrentCellWithoutShipCellOnUnknownCell[0];

              echo 'There is only one possible way to place largest unconfirmed shape type ' . $shapeType->value
                . ' without covering unknown cells, so putting it at x: ' . $battleship->x . ', y: ' . $battleship->y . PHP_EOL;
              echo 'Confirmed shape types before placement: '
                . implode(', ', array_map(static fn(ShapeType $shapeType) => $shapeType->value, $this->getConfirmedShapeTypes())) . PHP_EOL;

              // Place the battleship on the map (and infer adjacent cells), mark it as confirmed
              $this->placeBattleship($battleship);
              $foundNewBattleships[] = $battleship;
            }
          }
        }
      }
    }
  }

  /**
   * Set the inferred grid cells to {@see Cell::WATER} if the value in the heat-map is zero
   * (aka there is no single possible way to place any of the remaining unconfirmed battleships).
   * This should only be called after the heat-map is fully recalculated!
   *
   * @return void
   */
  public function updateGridByHeatMapZeroes(): void
  {
    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        $cell = $this->grid[$x][$y];

        // can only update unknown cells
        if ($cell !== Cell::UNKNOWN) {
          continue;
        }

        // Check that the heat-map value is zero
        // NOTE: Safe to do since 0 will always be stored as int !!!
        if ($this->heatMap[$x][$y] > self::HEATMAP_NO_VALUE) {
          continue;
        }

        $this->grid[$x][$y] = Cell::WATER;
        $this->possibleShapeTypesGrid[$x][$y] = [];
      }
    }
  }

  /**
   * Recalculate heat map by ALL possible shape type placements (basic).
   *
   * @return bool Target mode.
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function recalculateHeatMapByUnconfirmedShapeTypePlacementsBasic(): bool
  {
    $unconfirmedShapeTypes = $this->getUnconfirmedShapeTypes();
    $cellFrequencies = [];
    $targetMode = false;

    foreach ($unconfirmedShapeTypes as $shapeType) {
      $possibleBattleshipsWithFlags = $this->calculatePossibleBattleshipsForShapeType($shapeType);

      if (empty($possibleBattleshipsWithFlags)) {
        echo '------ EXCEPTION ------' . PHP_EOL;
        echo $this;
        echo '-----------------------' . PHP_EOL;
        echo 'Unconfirmed shape types: ' . implode(', ', array_map(static fn (ShapeType $shapeType) => $shapeType->value, $unconfirmedShapeTypes)) . PHP_EOL;

        throw new FatalException('Not found any possible battleships for unconfirmed shape type: ' . $shapeType->value);
      }

      foreach ($possibleBattleshipsWithFlags as $battleshipWithFlags) {
        $battleship = $battleshipWithFlags->battleship;

        if ($battleshipWithFlags->targetMode) {
          $targetMode = true;
        }

        foreach ($battleship->getShipCellCoordinates() as [$absX, $absY]) {
          $cellFrequencies[$absX][$absY] ??= 0; // MUST BE 0 !!!

          $frequency = 1;

          // An attempt to prioritize (x == 0, y != 0) & (x != 0, y == 0) battleships:
          if ($battleship->x === 0 && $battleship->y !== 0 && $battleship->rotation === Rotation::HORIZONTAL) {
            $frequency *= 2;
          }
          if ($battleship->x !== 0 && $battleship->y === 0 && $battleship->rotation === Rotation::VERTICAL) {
            $frequency *= 2;
          }

          $cellFrequencies[$absX][$absY] += ($battleshipWithFlags->targetMode
            ? $frequency * 100
            : $frequency);
        }
      }
    }

    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        if (!isset($cellFrequencies[$x][$y])) {
          continue; // must continue !!!
        }

        // Only update the heat-map value if the cell is undiscovered server-side
        if ($this->originalGrid[$x][$y] !== Cell::UNKNOWN) {
          continue;
        }

        // Must not be cast to int !!!
        if ($this->grid[$x][$y] === Cell::SHIP) {
          $this->heatMap[$x][$y] = self::HEATMAP_MAXIMUM_VALUE;
        } else {
          $this->heatMap[$x][$y] = $cellFrequencies[$x][$y] * (self::$biasGrid[$x][$y] ?? 1);
        }
      }
    }

    return $targetMode;
  }

  /**
   * Recalculate heat map by ALL possible shape type placements (with checking for valid battleship configurations).
   *
   * @return bool Target mode.
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function recalculateHeatMapByUnconfirmedShapeTypePlacementsAdvanced(): bool
  {
    $unconfirmedShapeTypes = $this->getUnconfirmedShapeTypes();

    /** @var array<string, BattleshipWithFlags[]> $shapeTypeValueToPossibleBattleshipsWithFlagsMap */
    $shapeTypeValueToPossibleBattleshipsWithFlagsMap = [];

    $startTime = microtime(true);
    foreach ($unconfirmedShapeTypes as $shapeType) {
      $shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeType->value] = $this->calculatePossibleBattleshipsForShapeType($shapeType);

      if (empty($shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeType->value])) {
        echo '------ EXCEPTION ------' . PHP_EOL;
        echo $this;
        echo '-----------------------' . PHP_EOL;
        echo 'Unconfirmed shape types: ' . implode(', ', array_map(static fn (ShapeType $shapeType) => $shapeType->value, $unconfirmedShapeTypes)) . PHP_EOL;

        throw new FatalException('Not found any possible battleships for unconfirmed shape type: ' . $shapeType->value);
      }
    }
//    echo 'Time to calculate possible battleships: ' . (microtime(true) - $startTime) . ' seconds' . PHP_EOL;

    $locationFrequencies = new SplObjectStorage();
    $validConfigurationsCounted = 0;

    $startTime = microtime(true);

    $totalCombinations = 1;
    foreach ($unconfirmedShapeTypes as $shapeType) {
      $totalCombinations *= count($shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeType->value]);
    }

    // Check all possible configurations if there aren't that many of them
    if ($totalCombinations <= 10000000) {
      $this->calculateLocationFrequenciesByAllPossibleBattleshipConfigurationsRecursive(
        $shapeTypeValueToPossibleBattleshipsWithFlagsMap,
        array_reverse($unconfirmedShapeTypes),
        [],
        $locationFrequencies,
        $validConfigurationsCounted,
        null
      );
    } else {
      $validConfigurationsCounted = $this->calculateLocationFrequenciesByRandomPossibleBattleshipConfigurations(
        $shapeTypeValueToPossibleBattleshipsWithFlagsMap,
        $unconfirmedShapeTypes,
        $locationFrequencies,
        1000000,
        10000
      );
    }
    echo 'Time to calculate total configurations: ' . $totalCombinations . ', valid: ' . $validConfigurationsCounted
      . ', time: ' . (microtime(true) - $startTime) . ' seconds' . PHP_EOL;

    $targetMode = false;
    $cellFrequencies = [];
    foreach ($unconfirmedShapeTypes as $shapeType) {
      foreach ($shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeType->value] as $battleshipWithFlags) {
        $battleship = $battleshipWithFlags->battleship;

        if ($battleshipWithFlags->targetMode) {
          $targetMode = true;
        }

        foreach ($battleship->getShipCellCoordinates() as [$absX, $absY]) {
          $cellFrequencies[$absX][$absY] ??= 1; // MUST BE 1 !!!

          if (!$locationFrequencies->contains($battleship)) {
            continue;
          }

          $frequency = $locationFrequencies[$battleship];

          if ($battleship->x === 0 && $battleship->y !== 0) {
            $mod = 10.911439114391143;
            if ($battleship->rotation === Rotation::HORIZONTAL) {
              $frequency *= ($mod / 2);
            } else {
              // $frequency /= 2;
            }
          }
          else if ($battleship->x !== 0 && $battleship->y === 0) {
            $mod = 7.834123222748815;
            if ($battleship->rotation === Rotation::VERTICAL) {
              $frequency *= ($mod / 2);
            } else {
              // $frequency /= 2;
            }
          }

          $bias = self::$shapeTypeBiasGrid[$shapeType->value][$absX][$absY] ?? 1;
          $frequency *= $bias;

          $cellFrequencies[$absX][$absY] += ($battleshipWithFlags->targetMode
            ? $frequency * 100
            : $frequency);
        }
      }
    }

    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        if (!isset($cellFrequencies[$x][$y])) {
          continue; // must continue !!!
        }

        // Only update the heat-map value if the cell is undiscovered server-side
        if ($this->originalGrid[$x][$y] !== Cell::UNKNOWN) {
          continue;
        }

        // Must not be cast to int !!!
        if ($this->grid[$x][$y] === Cell::SHIP) {
          $this->heatMap[$x][$y] = self::HEATMAP_MAXIMUM_VALUE;
        } else {
          $this->heatMap[$x][$y] = ($cellFrequencies[$x][$y] * 1000.0 / $validConfigurationsCounted);
          $this->heatMap[$x][$y] *= (self::$biasGrid[$x][$y] ?? 1);
        }
      }
    }

    if ($targetMode) {
      echo $this->getMapWithHeatMapAsString();
    }

    return $targetMode;
  }

  /**
   * @param array            $shapeTypeValueToPossibleBattleshipsWithFlagsMap
   * @param array            $unconfirmedShapeTypes
   * @param SplObjectStorage $locationFrequencies Output.
   * @param int              $minIterations
   * @param int              $minValidConfigurations
   *
   * @return int Number of valid configurations.
   */
  public function calculateLocationFrequenciesByRandomPossibleBattleshipConfigurations(
    array &$shapeTypeValueToPossibleBattleshipsWithFlagsMap,
    array $unconfirmedShapeTypes,
    SplObjectStorage $locationFrequencies,
    int $minIterations,
    int $minValidConfigurations
  ): int
  {
    $shapeTypeValueToCount = [];
    $validConfigurationsCounted = 0;

    for ($iteration = 0; $iteration < $minIterations || $validConfigurationsCounted < $minValidConfigurations; $iteration++) {
      $selectedBattleships = [];

      foreach ($unconfirmedShapeTypes as $shapeType) {
        $shapeTypeValue = $shapeType->value;

        $possibleBattleshipsWithFlags = $shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeTypeValue];
        $shapeTypeValueToCount[$shapeType->value] ??= count($shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeTypeValue]);

        $randomBattleship = $possibleBattleshipsWithFlags[mt_rand(0, $shapeTypeValueToCount[$shapeTypeValue] - 1)]->battleship;
        // $randomBattleship = $possibleBattleshipsWithFlags[array_rand($possibleBattleshipsWithFlags)]->battleship;

        foreach ($selectedBattleships as $selectedBattleship) {
          if (MapHelpers::areBattleshipsIncompatible($randomBattleship, $selectedBattleship)) {
            continue 3;
          }
        }

        $selectedBattleships[] = $randomBattleship;
      }

      foreach ($selectedBattleships as $selectedBattleship) {
        if (!$locationFrequencies->contains($selectedBattleship)) {
          $locationFrequencies[$selectedBattleship] = 0;
        }

        $locationFrequencies[$selectedBattleship] = $locationFrequencies[$selectedBattleship] + 1;
      }

      $validConfigurationsCounted++;
    }

    return $validConfigurationsCounted;
  }

  /**
   * Calculate location frequencies by ALL possible battleship configurations.
   *
   * @param array<string, BattleshipWithFlags[]> $shapeTypeValueToPossibleBattleshipsWithFlagsMap &reference! (probably unnecessary tho)
   * @param ShapeType[]                          $unconfirmedShapeTypesRemaining
   * @param Battleship[]                         $selectedBattleships
   * @param SplObjectStorage                     $locationFrequencies                             Output.
   * @param int                                  $validConfigurationsCounted                      &reference! Output.
   * @param int|null                             $maxValidConfigurations                          Limit.
   *
   * @return int Number of valid configurations.
   */
  public function calculateLocationFrequenciesByAllPossibleBattleshipConfigurationsRecursive(
    array &$shapeTypeValueToPossibleBattleshipsWithFlagsMap,
    array $unconfirmedShapeTypesRemaining,
    array $selectedBattleships,
    SplObjectStorage $locationFrequencies,
    int &$validConfigurationsCounted,
    ?int $maxValidConfigurations
  ): int
  {
    // Konec rekurze
    if (empty($unconfirmedShapeTypesRemaining)) {
      foreach ($selectedBattleships as $selectedBattleship) {
        if (!$locationFrequencies->contains($selectedBattleship)) {
          $locationFrequencies[$selectedBattleship] = 0;
        }

        $locationFrequencies[$selectedBattleship] = $locationFrequencies[$selectedBattleship] + 1;
      }

      $validConfigurationsCounted++;
      return $validConfigurationsCounted;
    }

    $shapeType = array_pop($unconfirmedShapeTypesRemaining);

    foreach ($shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeType->value] as $battleshipWithFlags) {
      $battleship = $battleshipWithFlags->battleship;

      foreach ($selectedBattleships as $selectedBattleship) {
        if (MapHelpers::areBattleshipsIncompatible($battleship, $selectedBattleship)) {
          continue 2;
        }
      }

      $selectedBattleships[] = $battleship;
      $this->calculateLocationFrequenciesByAllPossibleBattleshipConfigurationsRecursive($shapeTypeValueToPossibleBattleshipsWithFlagsMap, $unconfirmedShapeTypesRemaining, $selectedBattleships, $locationFrequencies, $validConfigurationsCounted, $maxValidConfigurations);
      array_pop($selectedBattleships);

      // Brzda
      if ($maxValidConfigurations !== null && $validConfigurationsCounted >= $maxValidConfigurations) {
        return $validConfigurationsCounted;
      }
    }

    return $validConfigurationsCounted;
  }

  /**
   * Return all possible starting coordinates & rotations for placing a battleship.
   *
   * @param ShapeType $shapeType
   *
   * @return BattleshipWithFlags[]
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function calculatePossibleBattleshipsForShapeType(ShapeType $shapeType): array
  {
    /** @var Battleship[] $allPossibleBattleships */
    $allPossibleBattleships = [];

    /** @var array<int, array<int, array<string, bool>>> $isPartOfDiscoveredShipCellMap [x] => [y] => [rotation value] => flag. */
    $isPartOfDiscoveredShipCellMap = [];

    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        $cell = $this->grid[$x][$y];

        // Skip WATER right away
        if ($cell === Cell::WATER) {
          continue;
        }

        foreach ($this->calculatePossibleBattleshipsForShapeTypeAndCoordinates($shapeType, $x, $y) as $battleship) {
          [$cX, $cY, $rotation] = [$battleship->x, $battleship->y, $battleship->rotation];
          $isPartOfDiscoveredShipCellMap[$cX][$cY][$rotation->value] ??= false;

          if ($cell === Cell::SHIP) {
            $isPartOfDiscoveredShipCellMap[$cX][$cY][$rotation->value] = true;
          }

          // Just do basic array merge
          $allPossibleBattleships[] = $battleship;
        }
      }
    }

    /** @var BattleshipWithFlags[] $result */
    $result = [];

    // Filter unique possible battleships
    $uniquePossibleBattleships = self::filterUniqueBattleships($allPossibleBattleships);

    // Append the probability modifier to the end of each coordinate result...
    foreach($uniquePossibleBattleships as $battleship) {
      $result[] = new BattleshipWithFlags(
        $battleship,
        targetMode: $isPartOfDiscoveredShipCellMap[$battleship->x][$battleship->y][$battleship->rotation->value]
      );
    }

    return $result;
  }

  /**
   * Return all possible starting coordinates & rotations for placing a battleship of which any of the ships
   * occupied cells (type {@see Cell::SHIP}) would be placed on the $x and $y coordinates on the map.
   *
   * @param ShapeType $shapeType Shape type to calculate starting coordinates for.
   * @param int       $x         X coordinate of a discovered {@see Cell::SHIP} cell on the map.
   * @param int       $y         Y coordinate of a discovered {@see Cell::SHIP} cell on the map.
   * @param bool      $disallowShipCellOnUnknownCell
   *
   * @return Battleship[]
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function calculatePossibleBattleshipsForShapeTypeAndCoordinates(ShapeType $shapeType, int $x, int $y, bool $disallowShipCellOnUnknownCell = false): array
  {
    // If the shape type is already confirmed, we can no longer place it anywhere
    if ($this->isShapeTypeConfirmed($shapeType)) {
      return [];
    }

    $cell = $this->grid[$x][$y];
    if ($cell === Cell::WATER) {
      return [];
    }

    /** @var Battleship[] $result */
    $result = [];

    $shape = Shape::fromShapeType($shapeType);
    foreach ($shape->getOccupiedCellCoordinates() as $coordinate) {
      [$shapeX, $shapeY] = $coordinate;

      $battleshipX = $x - $shapeX;
      $battleshipY = $y - $shapeY;

      $battleshipToTest = new Battleship($shape, $battleshipX, $battleshipY, Rotation::VERTICAL);
      if ($this->canPlaceBattleship($battleshipToTest, $disallowShipCellOnUnknownCell)) {
        $result[] = $battleshipToTest;
      }

      // Swap X/Y for HORIZONTAL rotation
      [$shapeX, $shapeY] = [$shapeY, $shapeX];
      $battleshipX = $x - $shapeX;
      $battleshipY = $y - $shapeY;

      $battleshipToTest = new Battleship($shape, $battleshipX, $battleshipY, Rotation::HORIZONTAL);
      if ($this->canPlaceBattleship($battleshipToTest, $disallowShipCellOnUnknownCell)) {
        $result[] = $battleshipToTest;
      }
    }

    return self::filterUniqueBattleships($result);
  }

  /**
   * Return true if there is ANY possibility that battleship with its location could be placed on the map.
   *
   * @param Battleship $battleship
   * @param bool $disallowShipCellOnUnknownCell
   *
   * @return bool
   * @throws OutOfBoundsException
   */
  public function canPlaceBattleship(Battleship $battleship, bool $disallowShipCellOnUnknownCell = false): bool
  {
    // Validate that bounding coordinates are not out of bounds
    if (!$this->areCoordinatesOnMap($battleship->x, $battleship->y)) {
      return false;
    }
    if (!$this->areCoordinatesOnMap($battleship->x + $battleship->getWidth() - 1, $battleship->y + $battleship->getHeight() - 1)) {
      return false;
    }

    // Validate ship cell fields
    for ($x = 0; $x < $battleship->getWidth(); $x++) {
      for ($y = 0; $y < $battleship->getHeight(); $y++) {
        $mapX = $battleship->x + $x;
        $mapY = $battleship->y + $y;

        $mapCell = $this->grid[$mapX][$mapY];
        $shipCell = $battleship->getCell($x, $y);

        if (($mapCell === Cell::SHIP && $shipCell === Cell::WATER) || ($mapCell === Cell::WATER && $shipCell === Cell::SHIP)) {
          return false;
        }

        if ($disallowShipCellOnUnknownCell && $mapCell === Cell::UNKNOWN && $shipCell === Cell::SHIP) {
          return false;
        }

        // If the SHIP cell can only be one of certain shape types,
        // check that the shape of the battleship is one of the possible shape types
        if ($shipCell === Cell::SHIP
            && !empty($this->possibleShapeTypesGrid[$mapX][$mapY])
            && !in_array($battleship->shape->getShapeType(), $this->possibleShapeTypesGrid[$mapX][$mapY], true)) {
          return false;
        }

        // Validate that the ship won't touch any other ship, not even diagonally
        if ($shipCell === Cell::SHIP) {
          for ($i = -1; $i <= 1; $i++) {
            for ($j = -1; $j <= 1; $j++) {
              if (!$this->areCoordinatesOnMap($mapX + $i, $mapY + $j)) {
                continue;
              }

              // If the neighbor cell on the map is not SHIP, no need to check it further
              if ($this->grid[$mapX + $i][$mapY + $j] !== Cell::SHIP) {
                continue; // alls good
              }

              $shipCellRelX = $x + $i;
              $shipCellRelY = $y + $j;

              // Default to true
              $neighborShipCellIsThisShip = true;

              // Check that the coords are not out of bounds for the shape
              if ($shipCellRelX < 0 || $shipCellRelX >= $battleship->getWidth()) {
                $neighborShipCellIsThisShip = false;
              }
              else if ($shipCellRelY < 0 || $shipCellRelY >= $battleship->getHeight()) {
                $neighborShipCellIsThisShip = false;
              }

              // The actual check against this ship
              if ($neighborShipCellIsThisShip) {
                $neighborShipCell = $battleship->getCell($shipCellRelX, $shipCellRelY);
                $neighborShipCellIsThisShip = $neighborShipCell === Cell::SHIP;
              }

              // Touch-point is another ship :(
              if (!$neighborShipCellIsThisShip) {
                return false;
              }
            }
          }
        }
      }
    }

    return true;
  }

  /**
   * Place battleship on the map.
   * Updates the heat-map accordingly.
   *
   * @param Battleship $battleship
   *
   * @return void
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function placeBattleship(Battleship $battleship): void
  {
    if (!$this->canPlaceBattleship($battleship)) {
      throw new EngineException('Cannot place battleship with location ('
        . $battleship->x . ', ' . $battleship->y . ') and rotation ' . $battleship->rotation->name . ' on the map');
    }

    $shapeType = $battleship->shape->getShapeType();

    // Mark as confirmed
    if ($this->shapeTypeValueToIsConfirmedMap[$shapeType->value]) {
      echo '------ EXCEPTION ------' . PHP_EOL;
      echo $this;
      echo '-----------------------' . PHP_EOL;
      echo 'Confirmed shape types: ' . implode(', ', array_map(static fn (ShapeType $shapeType) => $shapeType->value, $this->getConfirmedShapeTypes())) . PHP_EOL;

      throw new FatalException('Cannot place battleship for already confirmed shape type: ' . $shapeType->value);
    }
    $this->shapeTypeValueToIsConfirmedMap[$shapeType->value] = true;

    // Place on the map
    for ($relX = 0; $relX < $battleship->getWidth(); $relX++) {
      for ($relY = 0; $relY < $battleship->getHeight(); $relY++) {
        $cell = $battleship->getCell($relX, $relY);

        $mapX = $battleship->x + $relX;
        $mapY = $battleship->y + $relY;

        // Should be safe to do, due to the rule where no ship can touch any other ship :)
        $this->grid[$mapX][$mapY] = $cell;

        // Update heatmap
        if ($cell === Cell::SHIP) {
          $this->heatMap[$mapX][$mapY] = self::HEATMAP_MAXIMUM_VALUE;
        } else if ($cell === Cell::WATER) {
          $this->heatMap[$mapX][$mapY] = 0;
        }

        if ($cell === Cell::SHIP) {
          // Mark SHIP cell as a cell containing a confirmed battleship
          $this->confirmedBattleshipsGrid[$mapX][$mapY] = $battleship;

          // The only possible shape type is the shape type of the battleship that's being placed right now
          $this->possibleShapeTypesGrid[$mapX][$mapY] = [$battleship->shape->getShapeType()];

          // TODO: Rewrite + reset
          //          $this->possibleShapeTypesGrid[$x][$y] = [];
          // Mark adjacent cells as water (dirty, these might get overwritten in the next cycle)
          for ($i = -1; $i <= 1; $i++) {
            for ($j = -1; $j <= 1; $j++) {
              if (!$this->areCoordinatesOnMap($mapX + $i, $mapY + $j)) {
                continue;
              }

              // Dirty solution, but I'm too lazy to do it properly... we could do more checks here but MEH
              if ($this->grid[$mapX + $i][$mapY + $j] === Cell::UNKNOWN) {
                $this->heatMap[$mapX + $i][$mapY + $j] = 0;
                $this->grid[$mapX + $i][$mapY + $j] = Cell::WATER;
              }
            }
          }
        }
      }
    }
  }

  /**
   * Return coordinates of the next undiscovered cell with the highest probability of a hit.
   *
   * @return array{0: int, 1: int, 2: int}|null
   */
  public function getNextUndiscoveredShipCell(): ?array
  {
    $highestProbability = 0;
    $highestProbabilityCoordinates = [];

    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        $originalCell = $this->originalGrid[$x][$y];
        $cell = $this->grid[$x][$y];
        $probability = $this->heatMap[$x][$y];

        // We can only fire on undiscovered cells
        if ($originalCell !== Cell::UNKNOWN) {
          continue;
        }

        if ($probability > $highestProbability) {
          $highestProbability = $probability;
          $highestProbabilityCoordinates = [];
        }

        if ($probability === $highestProbability) {
          $highestProbabilityCoordinates[] = [$x, $y];
        }
      }
    }

    if (empty($highestProbabilityCoordinates)) {
      return null;
    }

    $count = count($highestProbabilityCoordinates);
    if ($count > 1) {
      echo 'Found more than one coordinate with highest probability of: ' . $highestProbability . ', count: ' . $count . PHP_EOL;
    }

    // Center bias setting
    $enableDistanceToCenterBias = false;

    // If center bias setting is enabled, choose coordinates closest to the center
    if ($enableDistanceToCenterBias) {
      $leastDistanceToCenter = 9999.0;
      $leastDistanceToCenterCoordinate = null;

      // If there are multiple coordinates with the same probability, choose the one that is closer to the center
      foreach ($highestProbabilityCoordinates as [$cX, $cY]) {
        $d = $this->getDistanceToCenter($cX, $cY);
        if ($d < $leastDistanceToCenter) {
          $leastDistanceToCenter = $d;
          $leastDistanceToCenterCoordinate = [$cX, $cY];
        }
      }

      // Return coordinates with the highest probability that are closest to the center
      return [$leastDistanceToCenterCoordinate[0], $leastDistanceToCenterCoordinate[1], $highestProbability];
    }

    // Return random coordinates with the highest probability
    $coordinates = $highestProbabilityCoordinates[array_rand($highestProbabilityCoordinates)];
    return [$coordinates[0], $coordinates[1], $highestProbability];
  }

  /**
   * Calculate the distance of the supplied coordinates to the center of the map.
   *
   * @param int $x
   * @param int $y
   *
   * @return float
   */
  public function getDistanceToCenter(int $x, int $y): float
  {
    $midX = (self::WIDTH - 1) / 2;
    $midY = (self::HEIGHT - 1) / 2;

    return sqrt((abs($x - $midX) ** 2) + (abs($y - $midY) ** 2));
  }

  /**
   * Set a cell value to the inferred grid and update its heat-map value if it is SHIP or WATER.
   *
   * @param int  $x
   * @param int  $y
   * @param Cell $cell
   *
   * @return void
   * @throws OutOfBoundsException
   */
  public function setGridCell(int $x, int $y, Cell $cell): void
  {
    $this->validateCoordinates($x, $y);
    $this->grid[$x][$y] = $cell;

    if ($cell === Cell::WATER) {
      $this->heatMap[$x][$y] = self::HEATMAP_NO_VALUE;
    } else if ($cell === Cell::SHIP) {
      $this->heatMap[$x][$y] = self::HEATMAP_MAXIMUM_VALUE;
    }
  }

  /**
   * Mark a cell as 100% confirmed to be certainly one of given shape types.
   *
   * @param int         $x
   * @param int         $y
   * @param ShapeType[] $shapeTypes
   *
   * @return void
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws EngineException
   */
  public function setPossibleShipCellShapeTypes(int $x, int $y, array $shapeTypes): void
  {
    $this->validateCoordinates($x, $y);

    // Make sure that the cell at given coordinates is SHIP
    $cell = $this->grid[$x][$y];
    if ($cell !== Cell::SHIP) {
      throw new EngineException('Cannot update possible shape types of a cell that is not SHIP. cell type: ' . $cell->value);
    }

    // Store the possible shape types
    $this->possibleShapeTypesGrid[$x][$y] = $shapeTypes;

    /** @var array<int, array<int, true>> $allCoordinatesThatCouldBeAPartOfPossibleBattleship [x] => [y] => true. */
    $allCoordinatesThatCouldBeAPartOfPossibleBattleship = [];

    // For every possible shape type
    foreach ($shapeTypes as $shapeType) {
      // Calculate all battleships that could be placed so that one of theirs SHIP cells would overlap this SHIP cell
      $possibleBattleships = $this->calculatePossibleBattleshipsForShapeTypeAndCoordinates($shapeType, $x, $y);

      // Store all SHIP cell coordinates of every single possible battleship
      foreach ($possibleBattleships as $possibleBattleship) {
        foreach ($possibleBattleship->getShipCellCoordinates() as [$sX, $sY]) {
          $allCoordinatesThatCouldBeAPartOfPossibleBattleship[$sX][$sY] = true;
        }
      }
    }

    // Get the remaining shape types (aka impossible shape types).
    $otherShapeTypes = [];
    foreach (ShapeType::cases() as $shapeTypeForTest) {
      if (!in_array($shapeTypeForTest, $shapeTypes, true)) {
        $otherShapeTypes[] = $shapeTypeForTest;
      }
    }

    // If the possible shape types contains both SUBMARINE/DESTROYER,
    // we don't really know which one of those is at the given coordinates
    // Make sure we don't disallow placing them elsewhere!
    if (in_array(ShapeType::SUBMARINE, $shapeTypes, true)
        && in_array(ShapeType::DESTROYER, $shapeTypes, true)) {
      $otherShapeTypes[] = ShapeType::SUBMARINE;
      $otherShapeTypes[] = ShapeType::DESTROYER;
    }

    for ($x = 0; $x < self::WIDTH; $x++) {
      for ($y = 0; $y < self::HEIGHT; $y++) {
        // There is no need to update WATER cells
        if ($this->grid[$x][$y] === Cell::WATER) {
          continue;
        }

        // If the current cell could be a part of possible battleship, skip it
        if (isset($allCoordinatesThatCouldBeAPartOfPossibleBattleship[$x][$y])) {
          continue;
        }

        // Get the previously possible shape types on the current cell
        $previouslyPossibleShapeTypes = $this->possibleShapeTypesGrid[$x][$y];

        // If there were no possible shape types previously (we didn't know of any yet)
        if (empty($previouslyPossibleShapeTypes)) {
          // Constrain the possible shape types to the other shape types
          $this->possibleShapeTypesGrid[$x][$y] = $otherShapeTypes;
        }
        // If there were some possible shape types previously
        else {
          // Calculate intersect of the other shape types and the previous shape types for the current cell
          $newConfirmedShapeTypes = [];
          foreach ($previouslyPossibleShapeTypes as $previouslyPossibleShapeType) {
            if (!in_array($previouslyPossibleShapeType, $otherShapeTypes, true)) {
              continue;
            }

            $newConfirmedShapeTypes[] = $previouslyPossibleShapeType;
          }

          // Constrain the possible shape types to the intersect array
          $this->possibleShapeTypesGrid[$x][$y] = $newConfirmedShapeTypes;
        }
      }
    }
  }

  /**
   * Validate whether the given coordinates aren't out of bounds.
   *
   * @param int $x
   * @param int $y
   *
   * @return void
   * @throws OutOfBoundsException
   */
  public function validateCoordinates(int $x, int $y): void
  {
    if ($x < 0 || $x >= self::WIDTH) {
      throw new OutOfBoundsException('X coordinate out of bounds: [0, ' . (self::WIDTH - 1) . '], given: ' . $x);
    }
    if ($y < 0 || $y >= self::HEIGHT) {
      throw new OutOfBoundsException('Y coordinate out of bounds: [0, ' . (self::HEIGHT - 1) . '], given: ' . $y);
    }
  }

  /**
   * Return true if the given coordinates aren't out of bounds.
   *
   * @param int $x
   * @param int $y
   *
   * @return bool
   */
  public function areCoordinatesOnMap(int $x, int $y): bool
  {
    if ($x < 0 || $x >= self::WIDTH) {
      return false;
    }
    if ($y < 0 || $y >= self::HEIGHT) {
      return false;
    }

    return true;
  }

  /**
   * Helper method to filter unique [SHAPE TYPE, X, Y, ROTATION] combinations of battleships in an array.
   *
   * @param Battleship[] $battleships
   *
   * @return Battleship[]
   */
  private static function filterUniqueBattleships(array $battleships): array
  {
    $unique = [];
    $found = [];

    foreach ($battleships as $battleship) {
      [$shapeTypeValue, $x, $y, $rotation] = [$battleship->shape->getShapeType()->value, $battleship->x, $battleship->y, $battleship->rotation];

      if (isset($found[$shapeTypeValue][$x][$y][$rotation->value])) {
        continue;
      }

      $found[$shapeTypeValue][$x][$y][$rotation->value] = true;
      $unique[] = $battleship;
    }

    return $unique;
  }

  /**
   * Set the bias grid.
   *
   * @param string $jsonData
   *
   * @return void
   * @throws SystemException
   */
  public static function setBiasGridByJsonData(string $jsonData): void
  {
    try {
      $biasGrid = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new SystemException('Failed to parse json data of bias grid', $e->getCode(), $e);
    }

    self::$biasGrid = $biasGrid;
  }

  /**
   * Set the shape type bias grid.
   *
   * @param string $jsonData
   *
   * @return void
   * @throws SystemException
   */
  public static function setShapeTypeBiasGridByJsonData(string $jsonData): void
  {
    try {
      $biasGrid = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new SystemException('Failed to parse json data of shape type bias grid', $e->getCode(), $e);
    }

    self::$shapeTypeBiasGrid = $biasGrid;
  }

  /**
   * Return map as a human-readable string.
   *
   * @return string
   */
  public function __toString(): string
  {
    $str = '';

    for ($y = 0; $y < self::HEIGHT; $y++) {
      for ($x = 0; $x < self::WIDTH; $x++) {
        $str .= '     ' . $this->grid[$x][$y]->value . ' ';
      }

      $str .= PHP_EOL;
    }

    return $str;
  }

  /**
   * Return heat-map as a human-readable string.
   *
   * @return string
   */
  public function getHeatMapAsString(): string
  {
    $str = '';

    for ($y = 0; $y < self::HEIGHT; $y++) {
      for ($x = 0; $x < self::WIDTH; $x++) {
        $number = number_format($this->heatMap[$x][$y], 0, '.', '');
        $str .= str_pad($number, 6, ' ', STR_PAD_LEFT) . ' ';
      }

      $str .= PHP_EOL;
    }

    return $str;
  }

  /**
   * Return map with heat-map (side by side) as a human-readable string.
   *
   * @return string
   */
  public function getMapWithHeatMapAsString(): string
  {
    $str = '';

    for ($y = 0; $y < self::HEIGHT; $y++) {
      for ($x = 0; $x < self::WIDTH; $x++) {
        $cellValue = $this->grid[$x][$y]->value;
        if ($this->confirmedBattleshipsGrid[$x][$y] !== null) {
          $cellValue = 'C';
        }

        if ($this->originalGrid[$x][$y] === Cell::SHIP) {
          $cellValue = '@';
        }

        $str .= ' ' . $cellValue . ' ';
      }

      $str .= '          ';

      for ($x = 0; $x < self::WIDTH; $x++) {
        $number = number_format($this->heatMap[$x][$y], 0, '.', '');
        $str .= str_pad($number, 6, ' ', STR_PAD_LEFT) . ' ';
      }

      $str .= PHP_EOL;
    }

    return $str;
  }
}
