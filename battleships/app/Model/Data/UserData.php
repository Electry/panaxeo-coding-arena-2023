<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Data;

use JsonSerializable;
use Override;

/**
 * User data.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final class UserData implements JsonSerializable
{
  /**
   * Constructor.
   *
   * @param int      $attempts                How many games did the player attempt to play (includes unfinished games).
   * @param int|null $lastMapId               Previous map id (used to generate next map id in the sequence).
   * @param int      $remainingMapCountInGame How many maps does the player need to finish in order to finish the current game.
   * @param int|null $bestScore               Best score across all games that the player successfully finished.
   * @param int|null $currentGameScore        Score for current ongoing game (accumulative/incomplete).
   */
  public function __construct(
    public int $attempts,
    public ?int $lastMapId,
    public int $remainingMapCountInGame,
    public ?int $bestScore,
    public ?int $currentGameScore
  )
  {
  }

  #[Override]
  public function jsonSerialize(): array
  {
    return [
      'attempts' => $this->attempts,
      'last_map_id' => $this->lastMapId,
      'remaining_map_count_in_game' => $this->remainingMapCountInGame,
      'best_score' => $this->bestScore,
      'current_game_score' => $this->currentGameScore
    ];
  }

  /**
   * Unserialize from json data.
   *
   * @param array{
   *   attempts: int,
   *   last_map_id: ?int,
   *   remaining_map_count_in_game: int,
   *   best_score: ?int,
   *   current_game_score: ?int
   * } $data
   *
   * @return self
   */
  public static function jsonUnserialize(array $data): self
  {
    return new self(
      $data['attempts'],
      $data['last_map_id'],
      $data['remaining_map_count_in_game'],
      $data['best_score'],
      $data['current_game_score']
    );
  }
}
