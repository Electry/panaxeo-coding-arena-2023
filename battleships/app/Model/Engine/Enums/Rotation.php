<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Engine\Enums;

/**
 * Rotation of a ship.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-29
 */
enum Rotation: string
{
  /** Top to bottom. */
  case VERTICAL = 'v';

  /** Left to right. */
  case HORIZONTAL = 'h';
}
