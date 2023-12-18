<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Engine;

use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Model\Engine\Enums\Cell;
use Electry\Battleships\Model\Engine\Enums\ShapeType;
use JsonSerializable;
use Override;

/**
 * Battleship shape.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final class Shape implements JsonSerializable
{
  /** @var Cell[][] Grid of cells that the ship occupies. Unoccupied fields are marked as {@see Cell::WATER}. */
  private readonly array $grid;

  /** @var int Width of the shape (maximum). */
  private readonly int $width;

  /** @var int Height of the shape (maximum). */
  private readonly int $height;

  /** @var int Total number of occupied cells (with cell type {@see Cell::SHIP}). */
  private readonly int $occupiedCells;

  /** @var array{0: int, 1: int}[] All relative coordinates of occupied cells (with cell type {@see Cell::SHIP}). */
  private readonly array $occupiedCellCoordinates;

  /** @var array<ShapeType, Shape> Singleton. */
  private static array $shapes = [];

  /**
   * Constructor.
   *
   * @param ShapeType $shapeType
   *
   * @throws FatalException
   */
  private function __construct(private readonly ShapeType $shapeType)
  {
    $grid = match ($shapeType) {
      ShapeType::HELICARRIER => [
        [Cell::WATER, Cell::SHIP, Cell::WATER],
        [Cell::SHIP,  Cell::SHIP, Cell::SHIP],
        [Cell::WATER, Cell::SHIP, Cell::WATER],
        [Cell::SHIP,  Cell::SHIP, Cell::SHIP],
        [Cell::WATER, Cell::SHIP, Cell::WATER],
      ],
      ShapeType::CARRIER => [
        [Cell::SHIP],
        [Cell::SHIP],
        [Cell::SHIP],
        [Cell::SHIP],
        [Cell::SHIP]
      ],
      ShapeType::BATTLESHIP => [
        [Cell::SHIP],
        [Cell::SHIP],
        [Cell::SHIP],
        [Cell::SHIP]
      ],
      ShapeType::DESTROYER, ShapeType::SUBMARINE => [
        [Cell::SHIP],
        [Cell::SHIP],
        [Cell::SHIP]
      ],
      ShapeType::PATROL_BOAT => [
        [Cell::SHIP],
        [Cell::SHIP]
      ],
    };

    $this->setShapeGrid($grid);
  }

  /**
   * Create or return an existing instance of a shape with pre-calculated values.
   *
   * @param ShapeType $shapeType
   *
   * @return self
   * @throws FatalException
   */
  public static function fromShapeType(ShapeType $shapeType): self
  {
    if (isset(self::$shapes[$shapeType->value])) {
      return self::$shapes[$shapeType->value];
    }

    self::$shapes[$shapeType->value] = new Shape($shapeType);
    return self::$shapes[$shapeType->value];
  }

  /** @inheritDoc */
  #[Override]
  public function jsonSerialize(): string
  {
    return $this->shapeType->value;
  }

  /**
   * Unserialize from json data.
   *
   * @param string $data
   *
   * @return Shape
   * @throws FatalException
   */
  public static function jsonUnserialize(string $data): self
  {
    return self::fromShapeType(ShapeType::from($data));
  }

  /**
   * Update pre-calculated values based on the battleship shape.
   *
   * @param Cell[][] $grid
   *
   * @return void
   * @throws FatalException
   */
  private function setShapeGrid(array $grid): void
  {
    if (empty($grid)) {
      throw new FatalException('Empty grid');
    }

    $occupiedCells = 0;
    $occupiedCellCoordinates = [];
    $w = null;

    foreach ($grid as $y => $row) {
      $rowW = count($row);

      if ($w !== null && $w !== $rowW) {
        throw new FatalException('Invalid shape of a battleship');
      }

      $w = $rowW;

      foreach ($row as $x => $cell) {
        if ($cell === Cell::SHIP) {
          $occupiedCells++;
          $occupiedCellCoordinates[] = [$x, $y];
        }
      }
    }

    $this->grid = $grid;
    $this->width = $w;
    $this->height = count($grid);
    $this->occupiedCells = $occupiedCells;
    $this->occupiedCellCoordinates = $occupiedCellCoordinates;
  }

  /**
   * Return the shape type.
   *
   * @return ShapeType
   */
  public function getShapeType(): ShapeType
  {
    return $this->shapeType;
  }

  /**
   * Return the total width of the battleship shape.
   *
   * @return int
   */
  public function getWidth(): int
  {
    return $this->width;
  }

  /**
   * Return the total height of the battleship shape.
   *
   * @return int
   */
  public function getHeight(): int
  {
    return $this->height;
  }

  /**
   * Return number of cells that the SHIP occupies (WATER does not count).
   *
   * @return int
   */
  public function getOccupiedCells(): int
  {
    return $this->occupiedCells;
  }

  /**
   * Return a cell value based on the relative position.
   *
   * @param int $relX Top-left corner in vertical position. X = COLUMN (left to right)
   * @param int $relY Top-left corner in vertical position. Y = ROW (top to bottom)
   *
   * @return Cell
   * @throws OutOfBoundsException
   */
  public function getCell(int $relX, int $relY): Cell
  {
    if ($relX < 0 || $relX >= $this->width) {
      throw new OutOfBoundsException('Relative X coordinate out of bounds: [0, ' . ($this->width - 1) . '], given: ' . $relX);
    }
    if ($relY < 0 || $relY >= $this->height) {
      throw new OutOfBoundsException('Relative Y coordinate out of bounds: [0, ' . ($this->height - 1) . '], given: ' . $relY);
    }

    return $this->grid[$relY][$relX];
  }

  /**
   * Return all relative coordinates where {@see Cell::SHIP} cells are located.
   *
   * @return array{0: int, 1: int}[] [[relX1, relY1], [relX2, relY2], ...].
   */
  public function getOccupiedCellCoordinates(): array
  {
    return $this->occupiedCellCoordinates;
  }
}
