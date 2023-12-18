<?php declare(strict_types = 1);

namespace Electry\Battleships\Responses;

use Electry\Battleships\Exceptions\DataException;
use Override;

/**
 * FireResponse.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
readonly class FireResponse extends AResponse
{
  /**
   * Constructor.
   *
   * @param string $grid             144 chars (12x12 grid) representing updated state of map, '*' is unknown, 'X' is ship, '.' is water.
   * @param string $cell             Result after firing at given position ('.' or 'X'). This field may be empty ('') if player calls /fire endpoint or tries to fire at already revealed position.
   * @param bool   $result           Denotes if fire action was valid. E.g. if player calls /fire endpoint or fire at already revealed position, this field will be false.
   * @param bool   $avengerAvailable Avenger availability after the player's move.
   * @param int    $mapId            ID of the map, on which was called last player's move. This value will change when player beats current map.
   * @param int    $mapCount         Fixed number of maps which are required to complete before completing one full game.
   * @param int    $moveCount        Number of valid moves which were made on the current map. Invalid moves such as firing at the same position multiple times are not included.
   * @param bool   $finished         Denotes if player successfully finished currently ongoing game => if player completed mapCount maps. Valid move after getting true in this field results in new game (or error if player has already achieved max number of tries).
   */
  public function __construct(
    public string $grid,
    public string $cell,
    public bool $result,
    public bool $avengerAvailable,
    public int $mapId,
    public int $mapCount,
    public int $moveCount,
    public bool $finished
  )
  {
  }

  #[Override]
  public function jsonSerialize(): array
  {
    return [
      'grid' => $this->grid,
      'cell' => $this->cell,
      'result' => $this->result,
      'avengerAvailable' => $this->avengerAvailable,
      'mapId' => $this->mapId,
      'mapCount' => $this->mapCount,
      'moveCount' => $this->moveCount,
      'finished' => $this->finished
    ];
  }

  /**
   * Unserialize from json data.
   *
   * @param array{
   *   grid: string,
   *   cell: string,
   *   result: bool,
   *   avengerAvailable: bool,
   *   mapId: int,
   *   mapCount: int,
   *   moveCount: int,
   *   finished: bool
   * } $data
   *
   * @return self
   * @throws DataException
   */
  public static function jsonUnserialize(array $data): self
  {
    self::validateDataFields($data, ['grid', 'cell', 'result', 'avengerAvailable', 'mapId', 'mapCount', 'moveCount', 'finished']);

    return new FireResponse(
      grid: $data['grid'],
      cell: $data['cell'],
      result: $data['result'],
      avengerAvailable: $data['avengerAvailable'],
      mapId: $data['mapId'],
      mapCount: $data['mapCount'],
      moveCount: $data['moveCount'],
      finished: $data['finished']
    );
  }

  /**
   * Return the response as a human-readable string.
   *
   * @return string
   */
  public function __toString(): string
  {
    return '<FireResponse grid="' . $this->grid
      . '" cell="' . $this->cell
      . '" result="' . ($this->result ? 'true' : 'false')
      . '" avengerAvailable="' . ($this->avengerAvailable ? 'true' : 'false')
      . '" mapId="' . $this->mapId
      . '" mapCount="' . $this->mapCount
      . '" moveCount="' . $this->moveCount
      . '" finished="' . ($this->finished ? 'true' : 'false')
      . '" />';
  }
}
