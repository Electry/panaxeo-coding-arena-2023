<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Engine;

use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Model\Engine\Enums\Cell;
use Electry\Battleships\Model\Engine\Enums\Rotation;
use JsonSerializable;
use Override;

/**
 * Battleship with coordinates and rotation.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final readonly class Battleship implements JsonSerializable
{
  /** @var array{0: int, 1: int}[] All absolute coordinates of occupied cells (with cell type {@see Cell::SHIP}). */
  private array $shipCellCoordinates;

  /**
   * Constructor.
   *
   * @param Shape    $shape
   * @param int      $x        Top-left corner as placed on the map.
   * @param int      $y        Top-left corner as placed on the map.
   * @param Rotation $rotation
   */
  public function __construct(
    public Shape    $shape,
    public int      $x,
    public int      $y,
    public Rotation $rotation
  )
  {
    // Pre-calculate absolute coordinates based on the ship location
    $relativeCoordinates = $shape->getOccupiedCellCoordinates();
    $shipCellCoordinates = [];

    foreach ($relativeCoordinates as $relativeCoordinate) {
      [$relX, $relY] = $relativeCoordinate;

      if ($this->rotation === Rotation::HORIZONTAL) {
        [$relX, $relY] = [$relY, $relX];
      }

      $shipCellCoordinates[] = [$x + $relX, $y + $relY];
    }

    $this->shipCellCoordinates = $shipCellCoordinates;
  }

  /** @inheritDoc */
  #[Override]
  public function jsonSerialize(): array
  {
    return [
      'shape' => $this->shape->jsonSerialize(),
      'x' => $this->x,
      'y' => $this->y,
      'rotation' => $this->rotation->value
    ];
  }

  /**
   * Unserialize from json data.
   *
   * @param array{
   *   shape: string,
   *   x: int,
   *   y: int,
   *   rotation: string
   * } $data
   *
   * @throws FatalException
   */
  public static function jsonUnserialize(array $data): self
  {
    return new Battleship(
      Shape::jsonUnserialize($data['shape']),
      $data['x'],
      $data['y'],
      Rotation::from($data['rotation'])
    );
  }

  /**
   * Return a cell value based on the relative position.
   * This method takes the battleship rotation into consideration.
   *
   * @param int $relX
   * @param int $relY
   *
   * @return Cell
   * @throws OutOfBoundsException
   */
  public function getCell(int $relX, int $relY): Cell
  {
    // Swap coordinates if the ship is rotated
    if ($this->rotation === Rotation::HORIZONTAL) {
      [$relX, $relY] = [$relY, $relX];
    }

    return $this->shape->getCell($relX, $relY);
  }

  /**
   * Return the total width of the battleship.
   * This method takes the battleship rotation into consideration.
   *
   * @return int
   */
  public function getWidth(): int
  {
    return $this->rotation === Rotation::VERTICAL ? $this->shape->getWidth() : $this->shape->getHeight();
  }

  /**
   * Return the total height of the battleship.
   * This method takes the battleship rotation into consideration.
   *
   * @return int
   */
  public function getHeight(): int
  {
    return $this->rotation === Rotation::VERTICAL ? $this->shape->getHeight() : $this->shape->getWidth();
  }

  /**
   * Return all absolute coordinates where {@see Cell::SHIP} cells are located.
   *
   * @return array{0: int, 1: int}[]
   */
  public function getShipCellCoordinates(): array
  {
    return $this->shipCellCoordinates;
  }
}
