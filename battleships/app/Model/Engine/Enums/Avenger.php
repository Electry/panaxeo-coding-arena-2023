<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Engine\Enums;

/**
 * Avenger.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-12-01
 */
enum Avenger: string
{
  /**
   * Thor's ability will hit 10 random map points at maximum (at maximum = if there are fewer untouched map points
   * available than 10, all of them will be targeted by this ability).
   */
  case THOR = 'thor';

  /**
   * Ironman's ability will return 1 map point of the smallest non-destroyed ship, this map point will be unaffected
   * (the purpose of this ability is to give a hint to the user).
   */
  case IRON_MAN = 'ironman';

  /**
   * Hulk's ability will destroy the whole ship if the map point specified by the row/column combination at the api
   * endpoint hits the ship (all the map points belonging to this ship will be marked as destroyed).
   */
  case HULK = 'hulk';
}
