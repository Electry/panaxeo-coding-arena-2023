<?php declare(strict_types = 1);

namespace Electry\Battleships\Bot\Engine;

use Electry\Battleships\Model\Engine\Battleship;

/**
 * Battleship with flags.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-12-02
 */
final readonly class BattleshipWithFlags
{
  /**
   * Constructor.
   *
   * @param Battleship $battleship
   * @param bool       $targetMode
   */
  public function __construct(
    public Battleship $battleship,
    public bool $targetMode
  )
  {
  }
}
