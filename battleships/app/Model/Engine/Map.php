<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Engine;

use Electry\Battleships\Exceptions\DataException;
use Electry\Battleships\Exceptions\Engine\EngineException;
use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Model\Engine\Data\AvengerResultData;
use Electry\Battleships\Model\Engine\Enums\Avenger;
use Electry\Battleships\Model\Engine\Enums\Cell;
use Electry\Battleships\Model\Engine\Enums\Rotation;
use Electry\Battleships\Model\Engine\Enums\ShapeType;
use JsonException;
use JsonSerializable;
use Override;
use Tracy\ILogger;

/**
 * Map.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final class Map implements JsonSerializable
{
  /** @var array<int, array<int, bool>> Grid: [x] => [y] => True if the cell was discovered by the player, false otherwise. */
  private array $discovered;

  /** @var array<int, array<int, Battleship|null>> Grid: [x] => [y] => {@see Battleship} object or null. */
  private array $grid;

  /** @var Battleship[] All battleships placed on the map. */
  public array $battleships;

  /** @var int Current move count. */
  private int $moveCount;

  /** @var bool True if avenger is available. */
  private bool $avengerAvailable;

  /**
   * Constructor.
   *
   * @param int $id
   * @param int $width
   * @param int $height
   */
  private function __construct(
    private readonly int $id,
    private readonly int $width,
    private readonly int $height
  )
  {
    $this->moveCount = 0;
    $this->avengerAvailable = false;

    // Reset battleships.
    $this->battleships = [];

    // Reset discovered & grid fields.
    $this->discovered = [];
    $this->grid = [];

    for ($x = 0; $x < $this->width; $x++) {
      $this->discovered[$x] = [];
      $this->grid[$x] = [];

      for ($y = 0; $y < $this->height; $y++) {
        $this->discovered[$x][$y] = false;
        $this->grid[$x][$y] = null;
      }
    }
  }

  /**
   * Create an empty map without placing any of the battleships on it.
   *
   * @param int $id
   * @param int $width
   * @param int $height
   *
   * @return self
   */
  public static function createEmpty(int $id, int $width, int $height): self
  {
    return new Map($id, $width, $height);
  }

  /**
   * Create a new map and place all the battleships at random locations.
   *
   * @param int $id
   * @param int $width
   * @param int $height
   *
   * @return self
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public static function createNew(int $id, int $width, int $height): self
  {
    $map = new Map($id, $width, $height);

    $shapeTypes = ShapeType::cases();

    // NOTE: Cannot shuffle, as we need to place largest battleships first!
    // there might not be enough space for the helicarrier if we place other ships terribly
    // shuffle($shapeTypes);

    foreach ($shapeTypes as $shapeType) {
      $shape = Shape::fromShapeType($shapeType);

      $allPossibleCoordinates = $map->generateAllPossibleCoordinatesForShape($shape);
      [$x, $y, $rotation] = $allPossibleCoordinates[array_rand($allPossibleCoordinates)];

      $battleship = new Battleship($shape, $x, $y, $rotation);
      $map->placeBattleship($battleship);
    }

    return $map;
  }

  /**
   * Create map from real game data.
   *
   * @param string  $fileName
   * @param int     $mapId
   *
   * @return self
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   * @throws DataException
   */
  public static function createFromRealGameData(string $fileName, int $mapId): self
  {
    $currentMapId = 0;
    $aiMap = null;

    $width = \Electry\Battleships\Bot\Engine\Map::WIDTH;
    $height = \Electry\Battleships\Bot\Engine\Map::HEIGHT;

    // TODO: Use provider for obtaining the base path
    $handle = fopen(__DIR__ . '/../../../data/' . $fileName, 'r');

    if ($handle) {
      while (($line = fgets($handle)) !== false) {
        if ($currentMapId === $mapId) {
          try {
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
          } catch (JsonException $e) {
            throw new DataException('Failed to unserialize map with id ' . $mapId
              . ' from the real game data', $e->getCode(), $e);
          }

          // Fixup unknown cells
          for ($x = 0; $x < $width; $x++) {
              for ($y = 0; $y < $height; $y++) {
                  if ($data['grid'][$x][$y] === Cell::UNKNOWN->value) {
                      $data['grid'][$x][$y] = Cell::WATER->value;
                  }
              }
          }

          $aiMap = \Electry\Battleships\Bot\Engine\Map::fromSerializedData($data);
          $aiMap->recalculateNewConfirmedShapeTypes();
        }

        $currentMapId++;
      }

      fclose($handle);
    }

    if ($aiMap === null) {
      throw new SystemException('Failed to create AI map from the json data');
    }

    $map = new Map($mapId, $width, $height);

    $battleships = $aiMap->getConfirmedShapeTypeValueToBattleshipMap();
    if (count($battleships) !== count(ShapeType::cases())) {
      throw new SystemException('Invalid number of battleships found in the map data: ' . count($battleships));
    }

    foreach ($battleships as $battleship) {
      $map->placeBattleship($battleship);
    }

    return $map;
  }

  /** @inheritDoc */
  #[Override]
  public function jsonSerialize(): array
  {
    $battleships = [];
    foreach ($this->battleships as $battleship) {
      $battleships[] = $battleship->jsonSerialize();
    }

    $discovered = [];
    foreach ($this->discovered as $x => $col) {
      foreach ($col as $y => $disc) {
        if ($disc) {
          $discovered[] = [$x, $y];
        }
      }
    }

    return [
      'id' => $this->id,
      'width' => $this->width,
      'height' => $this->height,
      'battleships' => $battleships,
      'discovered' => $discovered,
      'move_count' => $this->moveCount,
      'avenger_available' => $this->avengerAvailable
    ];
  }

  /**
   * Unserialize from json data.
   *
   * @param array{
   *   id: int,
   *   width: int,
   *   height: int,
   *   battleships: array,
   *   discovered: array{0: int, 1: int},
   *   move_count: int,
   *   avenger_available: bool
   * } $data
   *
   * @return self
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public static function jsonUnserialize(array $data): self
  {
    $map = new Map($data['id'], $data['width'], $data['height']);
    $map->moveCount = $data['move_count'];
    $map->avengerAvailable = $data['avenger_available'];

    foreach ($data['battleships'] as $battleship) {
      $map->placeBattleship(Battleship::jsonUnserialize($battleship));
    }

    foreach ($data['discovered'] as $discovered) {
      [$x, $y] = $discovered;
      $map->validateCoordinates($x, $y);
      $map->discovered[$x][$y] = true;
    }

    return $map;
  }

  /**
   * Return the map identifier.
   *
   * @return int
   */
  public function getId(): int
  {
    return $this->id;
  }

  /**
   * Return the width of the map.
   *
   * @return int
   */
  public function getWidth(): int
  {
    return $this->width;
  }

  /**
   * Return the height of the map.
   *
   * @return int
   */
  public function getHeight(): int
  {
    return $this->height;
  }

  /**
   * Return the current move count.
   *
   * @return int
   */
  public function getMoveCount(): int
  {
    return $this->moveCount;
  }

  /**
   * Return true if avenger is available.
   *
   * @return bool
   */
  public function isAvengerAvailable(): bool
  {
    return $this->avengerAvailable;
  }

  /**
   * Place battleship on the map.
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

    $this->battleships[] = $battleship;

    for ($relX = 0; $relX < $battleship->getWidth(); $relX++) {
      for ($relY = 0; $relY < $battleship->getHeight(); $relY++) {
        if ($battleship->getCell($relX, $relY) !== Cell::SHIP) {
          continue;
        }

        $midX = $battleship->x + $relX;
        $midY = $battleship->y + $relY;

        // Fatal check - should be validated in the canPlaceBattleship() method
        if ($this->grid[$midX][$midY] === Cell::SHIP) {
          throw new FatalException('Should NEVER EVER happen!!!');
        }

        // Store reference in the grid
        $this->grid[$midX][$midY] = $battleship;
      }
    }
  }

  /**
   * Generate an array of all possible coordinates and rotations for placing the given battleship shape.
   *
   * @param Shape $shape
   *
   * @return array{0: int, 1: int, 2: Rotation}
   * @throws FatalException
   */
  public function generateAllPossibleCoordinatesForShape(Shape $shape): array
  {
    $result = [];

    for ($x = 0; $x < $this->width; $x++) {
      for ($y = 0; $y < $this->height; $y++) {
        // Try vertical
        $battleship = new Battleship($shape, $x, $y, Rotation::VERTICAL);

        if ($this->canPlaceBattleship($battleship)) {
          $result[] = [$x, $y, Rotation::VERTICAL];
        }

        // Try horizontal
        $battleship = new Battleship($shape, $x, $y, Rotation::HORIZONTAL);

        if ($this->canPlaceBattleship($battleship)) {
          $result[] = [$x, $y, Rotation::HORIZONTAL];
        }
      }
    }

    return $result;
  }

  /**
   * Return true if the battleship with given location can be placed on the map.
   * Validates that the coordinates do not place any of the cells out of bounds,
   * and that the ship is not overlapping or placed next to any other ship.
   *
   * @param Battleship $battleship
   *
   * @return bool
   * @throws FatalException
   */
  private function canPlaceBattleship(Battleship $battleship): bool
  {
    // Validate that bounding coordinates are not out of bounds
    try
    {
      $this->validateCoordinates($battleship->x, $battleship->y);
      $this->validateCoordinates($battleship->x + $battleship->getWidth() - 1, $battleship->y + $battleship->getHeight() - 1);
    } catch (OutOfBoundsException) {
      return false;
    }

    // Validate that the space is not occupied
    for ($relX = 0; $relX < $battleship->getWidth(); $relX++) {
      for ($relY = 0; $relY < $battleship->getHeight(); $relY++) {
        try {
          if ($battleship->getCell($relX, $relY) !== Cell::SHIP) {
            continue;
          }
        } catch (OutOfBoundsException) {
          throw new FatalException('Should not happen');
        }

        $midX = $battleship->x + $relX;
        $midY = $battleship->y + $relY;

        // Validate that the ship won't touch any other ship, not even diagonally
        for ($i = -1; $i <= 1; $i++) {
          for ($j = -1; $j <= 1; $j++) {
            try {
              if ($this->isCellOccupied($midX + $i, $midY + $j)) {
                return false;
              }
            } catch (OutOfBoundsException) {
              // do nothing... (should only happend due to $i/$j pushing the midpoint over the map)
              // TODO: Stop using exceptions for flow control...
            }
          }
        }
      }
    }

    return true;
  }

  /**
   * Discover a cell (and increase move count, if not yet discovered).
   *
   * @param int $x
   * @param int $y
   *
   * @return Cell
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function fire(int $x, int $y): Cell
  {
    $this->validateCoordinates($x, $y);

    $cellBattleship = $this->getCellBattleship($x, $y);

    // If the player is about to hit a HELICARRIER battleship, store the discovery state before the hit
    if ($cellBattleship !== null && $cellBattleship->shape->getShapeType() === ShapeType::HELICARRIER) {
      $helicarrierDiscoveredBefore = $this->isBattleshipFullyDiscovered($cellBattleship);
    } else {
      $helicarrierDiscoveredBefore = false;
    }

    if (!$this->isCellDiscovered($x, $y)) {
      $this->discovered[$x][$y] = true;
      $this->moveCount++;
    }

    // If the player just hit a HELICARRIER battleship, and it was not fully discovered before, check for avengers
    if ($cellBattleship !== null && $cellBattleship->shape->getShapeType() === ShapeType::HELICARRIER && !$helicarrierDiscoveredBefore) {
      $helicarrierDiscoveredAfter = $this->isBattleshipFullyDiscovered($cellBattleship);
      if ($helicarrierDiscoveredAfter) {
        $this->avengerAvailable = true;
      }
    }

    return $this->getCell($x, $y);
  }

  /**
   * Discover a cell (and increase move count, if not yet discovered) with the use of an avenger ability.
   *
   * @param int                 $x
   * @param int                 $y
   * @param Avenger             $avenger
   * @param AvengerResultData[] $avengerResults &reference!
   *
   * @return Cell
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws EngineException
   */
  public function fireAvenger(int $x, int $y, Avenger $avenger, array &$avengerResults): Cell
  {
    if (!$this->avengerAvailable) {
      throw new EngineException('Cannot use avenger ability');
    }

    $this->validateCoordinates($x, $y);

    // TODO: Should it reset on invalid moves ???
    $this->avengerAvailable = false;

    // Do basic hit
    if (!$this->isCellDiscovered($x, $y)) {
      $this->discovered[$x][$y] = true;
      $this->moveCount++;
    }

    // Reset the avenger ability output array
    $avengerResults = [];

    // Handle avenger ability
    switch ($avenger) {
      case Avenger::THOR:
        $undiscoveredCellCoordinates = $this->getUndiscoveredCellCoordinates();
        shuffle($undiscoveredCellCoordinates);

        for ($i = 0; $i < 10; $i++) {
          // No more undiscovered cells?
          if (!isset($undiscoveredCellCoordinates[$i])) {
            break;
          }

          [$uX, $uY] = $undiscoveredCellCoordinates[$i];

          // Discover the cell
          $this->discovered[$uX][$uY] = true;

          // Format result
          $avengerResults[] = new AvengerResultData($uY, $uX, $this->isCellOccupied($uX, $uY));
        }

        break;

      case Avenger::IRON_MAN:
        $smallestNonDestroyedBattleship = $this->getSmallestNonDestroyedBattleship();
        if ($smallestNonDestroyedBattleship === null) {
          throw new FatalException('All battleships were already destroyed, the map should have ended by now!!!');
        }

        // var_dump('Smallest non-destroyed battleship: ');
        // echo $smallestNonDestroyedBattleship->shape->getShapeType()->value . PHP_EOL;

        $coordinates = $smallestNonDestroyedBattleship->getShipCellCoordinates();

        // Filter coordinates that were not discovered yet!
        $nonDiscoveredCoordinates = [];
        foreach ($coordinates as [$aX, $aY]) {
          if (!$this->isCellDiscovered($aX, $aY)) {
            $nonDiscoveredCoordinates[] = [$aX, $aY];
          }
        }

        shuffle($nonDiscoveredCoordinates);

        // Format result
        [$aX, $aY] = $nonDiscoveredCoordinates[array_key_first($nonDiscoveredCoordinates)];
        $avengerResults = [new AvengerResultData($aY, $aX, true)];
        break;

      case Avenger::HULK:
        // Get the battleship placed on the cell, if any
        $cellBattleship = $this->getCellBattleship($x, $y);
        if ($cellBattleship !== null) {
          foreach ($cellBattleship->getShipCellCoordinates() as [$sX, $sY]) {
            // Discover the cell
            $this->discovered[$sX][$sY] = true;

            // Format result
            $avengerResults[] = new AvengerResultData($sY, $sX, true);
          }
        }

        break;
    }

    return $this->getCell($x, $y);
  }

  /**
   * Return true if the cell was discovered.
   *
   * @param int $x
   * @param int $y
   *
   * @return bool
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function isCellDiscovered(int $x, int $y): bool
  {
    $this->validateCoordinates($x, $y);

    if (!array_key_exists($x, $this->discovered) || !array_key_exists($y, $this->discovered[$x])) {
      throw new FatalException($x . ', ' . $y . ' field not set in the discovered array');
    }

    return $this->discovered[$x][$y];
  }

  /**
   * Return true if the cell is occupied by some ship.
   *
   * @param int $x
   * @param int $y
   *
   * @return bool
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function isCellOccupied(int $x, int $y): bool
  {
    $this->validateCoordinates($x, $y);

    if (!array_key_exists($x, $this->grid) || !array_key_exists($y, $this->grid[$x])) {
      throw new FatalException($x . ', ' . $y . ' field not set in the grid array');
    }

    return $this->grid[$x][$y] !== null;
  }

  /**
   * Return a {@see Battleship} at given coordinates.
   *
   * @param int $x
   * @param int $y
   *
   * @return Battleship|null Null if there is no battleship at given coordinates.
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function getCellBattleship(int $x, int $y): ?Battleship
  {
    $this->validateCoordinates($x, $y);

    if (!array_key_exists($x, $this->grid) || !array_key_exists($y, $this->grid[$x])) {
      throw new FatalException($x . ', ' . $y . ' field not set in the grid array');
    }

    return $this->grid[$x][$y];
  }

  /**
   * Return true if given battleship is fully discovered (aka. destroyed).
   *
   * @param Battleship $battleship
   *
   * @return bool
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function isBattleshipFullyDiscovered(Battleship $battleship): bool
  {
    $coordinates = $battleship->getShipCellCoordinates();

    foreach ($coordinates as [$x, $y]) {
      if (!$this->isCellDiscovered($x, $y)) {
        return false;
      }
    }

    return true;
  }

  /**
   * Return true if all battleships placed on this map are fully discovered (aka. destroyed).
   *
   * @return bool
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function areAllBattleshipsFullyDiscovered(): bool
  {
    foreach ($this->battleships as $battleship) {
      if (!$this->isBattleshipFullyDiscovered($battleship)) {
        return false;
      }
    }

    return true;
  }

  /**
   * Return the smallest non-destroyed (not fully discovered) battleship.
   *
   * @return Battleship|null
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function getSmallestNonDestroyedBattleship(): ?Battleship
  {
    /** @var  $nonDestroyedBattleships */
    $nonDestroyedBattleships = [];

    foreach ($this->battleships as $battleship) {
      if (!$this->isBattleshipFullyDiscovered($battleship)) {
        $nonDestroyedBattleships[] = $battleship;
      }
    }

    // All battleships are fully discovered
    if (empty($nonDestroyedBattleships)) {
      return null;
    }

    // Ascending sort order
    usort($nonDestroyedBattleships, static fn (Battleship $a, Battleship $b) => $a->shape->getOccupiedCells() - $b->shape->getOccupiedCells());
    return $nonDestroyedBattleships[array_key_first($nonDestroyedBattleships)];
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
    if ($x < 0 || $x >= $this->width) {
      throw new OutOfBoundsException('X coordinate out of bounds: [0, ' . ($this->width - 1) . '], given: ' . $x);
    }
    if ($y < 0 || $y >= $this->height) {
      throw new OutOfBoundsException('Y coordinate out of bounds: [0, ' . ($this->height - 1) . '], given: ' . $y);
    }
  }

  /**
   * Return cell at given coordinates as a player would see it.
   *
   * @param int $x
   * @param int $y
   *
   * @return Cell
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function getCell(int $x, int $y): Cell
  {
    if (!$this->isCellDiscovered($x, $y)) {
      return Cell::UNKNOWN;
    }

    return $this->isCellOccupied($x, $y) ? Cell::SHIP : Cell::WATER;
  }

  /**
   * Return all discovered cell coordinates.
   *
   * @return array{0: int, 1: int}[]
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function getDiscoveredCellCoordinates(): array
  {
    /** @var array{0: int, 1: int}[] $result */
    $result = [];

    for ($x = 0; $x < $this->width; $x++) {
      for ($y = 0; $y < $this->height; $y++) {
        if ($this->isCellDiscovered($x, $y)) {
          $result[] = [$x, $y];
        }
      }
    }

    return $result;
  }

  /**
   * Return all undiscovered cell coordinates.
   *
   * @return array{0: int, 1: int}[]
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function getUndiscoveredCellCoordinates(): array
  {
    /** @var array{0: int, 1: int}[] $result */
    $result = [];

    for ($x = 0; $x < $this->width; $x++) {
      for ($y = 0; $y < $this->height; $y++) {
        if (!$this->isCellDiscovered($x, $y)) {
          $result[] = [$x, $y];
        }
      }
    }

    return $result;
  }

  /**
   * Serialize the map grid into a string.
   *
   * @return string
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public function __toString(): string
  {
    $str = '';

    for ($y = 0; $y < $this->height; $y++) {
      for ($x = 0; $x < $this->width; $x++) {
        $str .= $this->getCell($x, $y)->value;
      }
    }

    return $str;
  }
}
