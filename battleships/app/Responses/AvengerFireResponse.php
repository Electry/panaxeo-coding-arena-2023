<?php declare(strict_types = 1);

namespace Electry\Battleships\Responses;

use Electry\Battleships\Exceptions\DataException;
use Override;

/**
 * AvengerFireResponse includes all the fields from FireResponse and adds the following.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final readonly class AvengerFireResponse extends FireResponse
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
   * @param array{
   *   mapPoint: array{x: int, y: int},
   *   hit: bool
   * }[]           $avengerResult    mapPoint's values x (row) and y (column) denote coordinates which were affected by avenger ability. Value of hit denotes, if coordinates specified by mapPoint have hit a ship.
   */
  public function __construct(
    string $grid,
    string $cell,
    bool $result,
    bool $avengerAvailable,
    int $mapId,
    int $mapCount,
    int $moveCount,
    bool $finished,
    public array $avengerResult
  )
  {
    parent::__construct($grid, $cell, $result, $avengerAvailable, $mapId, $mapCount, $moveCount, $finished);
  }

  /**
   * Create new avenger fire response from existing fire response and avenger result data.
   *
   * @param FireResponse                                        $fireResponse
   * @param array{mapPoint: array{x: int, y: int}, hit: bool}[] $avengerResult
   *
   * @return self
   */
  public static function fromFireResponseAndAvengerResult(FireResponse $fireResponse, array $avengerResult): self
  {
    return new self(
      grid: $fireResponse->grid,
      cell: $fireResponse->cell,
      result: $fireResponse->result,
      avengerAvailable: $fireResponse->avengerAvailable,
      mapId: $fireResponse->mapId,
      mapCount: $fireResponse->mapCount,
      moveCount: $fireResponse->moveCount,
      finished: $fireResponse->finished,
      avengerResult: $avengerResult
    );
  }

  #[Override]
  public function jsonSerialize(): array
  {
    $base = parent::jsonSerialize();
    $base['avengerResult'] = $this->avengerResult;
    return $base;
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
   *   finished: bool,
   *   avengerResult: array{
   *     mapPoint: array{
   *       x: int,
   *       y: int
   *     },
   *     hit: bool
   *   }[]
   * } $data
   *
   * @return self
   * @throws DataException
   */
  public static function jsonUnserialize(array $data): self
  {
    self::validateDataField($data, 'avengerResult');

    foreach ($data['avengerResult'] as $avengerResult) {
      self::validateDataFields($avengerResult, ['mapPoint', 'hit']);
      self::validateDataFields($avengerResult['mapPoint'], ['x', 'y']);
    }

    $fireResponse = FireResponse::jsonUnserialize($data);
    return self::fromFireResponseAndAvengerResult($fireResponse, $data['avengerResult']);
  }

  /**
   * Return the response as a human-readable string.
   *
   * @return string
   */
  public function __toString(): string
  {
    /** @var string[] $avengerResults */
    $avengerResults = [];

    foreach ($this->avengerResult as $avengerResult) {
      $avengerResults[] = 'row: ' . $avengerResult['mapPoint']['x']
        . ', col: ' . $avengerResult['mapPoint']['y']
        . ', hit: ' . ($avengerResult['hit'] ? 'true' : 'false');
    }

    return '<AvengerFireResponse grid="' . $this->grid
      . '" cell="' . $this->cell
      . '" result="' . ($this->result ? 'true' : 'false')
      . '" avengerAvailable="' . ($this->avengerAvailable ? 'true' : 'false')
      . '" mapId="' . $this->mapId
      . '" mapCount="' . $this->mapCount
      . '" moveCount="' . $this->moveCount
      . '" finished="' . ($this->finished ? 'true' : 'false')
      . '" avengerResult=[' . implode(', ', $avengerResults)
      . '] />';
  }
}
