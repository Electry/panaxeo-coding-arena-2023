<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Engine\Enums;

/**
 * All ship shapes used in the game.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-29
 */
enum ShapeType: string
{
  case HELICARRIER = 'helicarrier';
  case CARRIER = 'carrier';
  case BATTLESHIP = 'battleship';
  case DESTROYER = 'destroyer';
  case SUBMARINE = 'submarine';
  case PATROL_BOAT = 'patrol_boat';
}
