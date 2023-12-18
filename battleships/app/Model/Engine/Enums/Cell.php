<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Engine\Enums;

/**
 * Cell type.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-29
 */
enum Cell: string
{
  /** Water. */
  case WATER = '.';

  /** Part of a ship. */
  case SHIP = 'X';

  /** Undiscovered field. */
  case UNKNOWN = '*';
}
