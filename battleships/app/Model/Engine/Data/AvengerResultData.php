<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Engine\Data;

use Electry\Battleships\Model\Engine\Enums\Cell;

/**
 * Single map-point result of an avenger ability.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-12-01
 */
final readonly class AvengerResultData
{
  /**
   * Constructor.
   *
   * @param int  $row    Y coordinate within our engine (X coordinate in the response!).
   * @param int  $column X coordinate within our engine (Y coordinate in the response!).
   * @param bool $hit    True if the discovered cell contains {@see Cell::SHIP} cell.
   */
  public function __construct(
    public int $row,
    public int $column,
    public bool $hit = false
  )
  {
  }
}
