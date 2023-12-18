<?php declare(strict_types = 1);

namespace Electry\Battleships\Bot\Engine;

use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Model\Engine\Battleship;
use Electry\Battleships\Model\Engine\Enums\Cell;
use Electry\Battleships\Model\Engine\Enums\ShapeType;
use Tracy\ILogger;

/**
 * Pre-computation for speedier evaluation.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-12-02
 */
final class MapHelpers
{
  /** @var array<string, BattleshipWithFlags[]> [shapeTypeValue] => array of BattleshipWithFlags. */
  private static array $shapeTypeValueToPossibleBattleshipsWithFlagsMap;

  /** @var array [shapeTypeValue1] => [x1] => [y1] => [rotationValue1] => [shapeTypeValue2] => [x2] => [y2] => [rotationValue2] => true. */
  private static array $incompatibleBattleships;

  /**
   * Static class.
   */
  private function __construct()
  {
  }

  /**
   * Precompute incompatible battleships.
   *
   * @param ILogger $logger
   *
   * @return void
   * @throws FatalException
   * @throws OutOfBoundsException
   */
  public static function precompute(ILogger $logger): void
  {
    $map = Map::createEmpty($logger);

    // TODO: Optimize by not comparing against map cells:
    $startTime = microtime(true);
    foreach (ShapeType::cases() as $shapeType) {
      self::$shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeType->value] = $map->calculatePossibleBattleshipsForShapeType($shapeType);
    }
    echo '  Time to calculate possible battleships: ' . (microtime(true) - $startTime) . ' seconds' . PHP_EOL;

    // Reset the cached array
    self::$incompatibleBattleships = [];

    // Initialize counters
    $checks = 0;
    $compatibleCount = 0;
    $incompatibleCount = 0;

    $startTime = microtime(true);
    foreach (ShapeType::cases() as $shapeType) {
      foreach (self::$shapeTypeValueToPossibleBattleshipsWithFlagsMap[$shapeType->value] as $battleshipWithFlags) {
        $battleship = $battleshipWithFlags->battleship;

        foreach (ShapeType::cases() as $otherShapeType) {
          // Cannot check two same shape types against each other
          if ($shapeType === $otherShapeType) {
            continue;
          }

          foreach (self::$shapeTypeValueToPossibleBattleshipsWithFlagsMap[$otherShapeType->value] as $otherBattleshipWithFlags) {
            $otherBattleship = $otherBattleshipWithFlags->battleship;

            // Check if these two would overlap
            $checks++;
            $incompatible = self::computeBattleshipsOverlapping($battleship, $otherBattleship);

            if ($incompatible) {
              $incompatibleCount++;

              self::$incompatibleBattleships
                [$battleship->shape->getShapeType()->value]
                [$battleship->x]
                [$battleship->y]
                [$battleship->rotation->value]
                [$otherBattleship->shape->getShapeType()->value]
                [$otherBattleship->x]
                [$otherBattleship->y]
                [$otherBattleship->rotation->value] = true;

              self::$incompatibleBattleships
                [$otherBattleship->shape->getShapeType()->value]
                [$otherBattleship->x]
                [$otherBattleship->y]
                [$otherBattleship->rotation->value]
                [$battleship->shape->getShapeType()->value]
                [$battleship->x]
                [$battleship->y]
                [$battleship->rotation->value] = true;
            } else {
              $compatibleCount++;
            }
          }
        }
      }
    }

    echo '  Time to calculate incompatibilities: ' . (microtime(true) - $startTime)
      . ' seconds, combinations: ' . $checks . ', compatible: ' . $compatibleCount . ', incompatible: ' . $incompatibleCount . PHP_EOL;
  }

  /**
   * Compute whether the two battleships are overlapping each other.
   *
   * @param Battleship $battleship1
   * @param Battleship $battleship2
   *
   * @return bool
   * @throws OutOfBoundsException
   */
  public static function computeBattleshipsOverlapping(Battleship $battleship1, Battleship $battleship2): bool
  {
    foreach ($battleship1->getShipCellCoordinates() as [$x1, $y1]) {
      // Check this + neighbor cells on battleship2
      for ($i = -1; $i <= 1; $i++) {
        for ($j = -1; $j <= 1; $j++) {
          $relX = $x1 - $battleship2->x + $i;
          $relY = $y1 - $battleship2->y + $j;

          // ZVALIDOVAT I, J ze neni oob pre battleship2
          if ($relX < 0 || $relX >= $battleship2->getWidth()) {
            continue;
          }
          if ($relY < 0 || $relY >= $battleship2->getHeight()) {
            continue;
          }

          // Overlapping SHIP cells (or neighboring)
          if ($battleship2->getCell($relX, $relY) === Cell::SHIP) {
            return true;
          }
        }
      }
    }

    foreach ($battleship2->getShipCellCoordinates() as [$x1, $y1]) {
      // Check this + neighbor cells on battleship2
      for ($i = -1; $i <= 1; $i++) {
        for ($j = -1; $j <= 1; $j++) {
          $relX = $x1 - $battleship1->x + $i;
          $relY = $y1 - $battleship1->y + $j;

          // ZVALIDOVAT I, J ze neni oob pre battleship2
          if ($relX < 0 || $relX >= $battleship1->getWidth()) {
            continue;
          }
          if ($relY < 0 || $relY >= $battleship1->getHeight()) {
            continue;
          }

          // Overlapping SHIP cells (or neighboring)
          if ($battleship1->getCell($relX, $relY) === Cell::SHIP) {
            return true;
          }
        }
      }
    }

    return false;
  }

  /**
   * Return true if the two battleships are overlapping each other.
   *
   * @param Battleship $battleship1
   * @param Battleship $battleship2
   *
   * @return bool
   */
  public static function areBattleshipsIncompatible(Battleship $battleship1, Battleship $battleship2): bool
  {
    return isset(
      self::$incompatibleBattleships
        [$battleship1->shape->getShapeType()->value]
        [$battleship1->x]
        [$battleship1->y]
        [$battleship1->rotation->value]
        [$battleship2->shape->getShapeType()->value]
        [$battleship2->x]
        [$battleship2->y]
        [$battleship2->rotation->value]
    );
  }
}
