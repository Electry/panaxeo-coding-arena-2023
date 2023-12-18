<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Services;

use Electry\Battleships\Exceptions\DataException;
use Electry\Battleships\Exceptions\Engine\EngineException;
use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Exceptions\NotFoundException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Model\Engine\Map;
use Electry\Battleships\Storage\IStorage;
use JsonException;

/**
 * Map service.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final readonly class MapService
{
  /**
   * Constructor.
   *
   * @param IStorage $storage
   */
  public function __construct(private IStorage $storage)
  {
  }

  /**
   * Obtain currently played map (if any).
   *
   * @param string $token
   *
   * @return Map
   * @throws EngineException
   * @throws FatalException
   * @throws NotFoundException
   * @throws OutOfBoundsException
   * @throws SystemException
   */
  public function getMap(string $token): Map
  {
    $serializedData = $this->storage->get(self::prepareMapStorageKey($token));

    try {
      $data = json_decode($serializedData, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new SystemException('Failed to decode json data: ' . $e->getMessage(), $e->getCode(), $e);
    }

    return Map::jsonUnserialize($data);
  }

  /**
   * Create and save a new map.
   *
   * @param string $token
   * @param int    $id
   *
   * @return Map
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   * @throws DataException
   */
  public function createMap(string $token, int $id): Map
  {
    // $map = Map::createNew($id, 12, 12);
    $map = Map::createFromRealGameData('submit_7.data', $id - 1000);

    $this->saveMap($token, $map);
    return $map;
  }

  /**
   * Save map.
   *
   * @param string $token
   * @param Map    $map
   *
   * @return void
   * @throws SystemException
   */
  public function saveMap(string $token, Map $map): void
  {
    try {
      $serializedData = json_encode($map, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new SystemException('Failed to serialize map data: ' . $e->getMessage(), $e->getCode(), $e);
    }

    $this->storage->set(self::prepareMapStorageKey($token), $serializedData);
  }

  /**
   * Delete current map.
   *
   * @param string $token
   *
   * @return bool
   * @throws SystemException
   */
  public function deleteMap(string $token): bool
  {
    return $this->storage->delete(self::prepareMapStorageKey($token));
  }

  /**
   * Prepare key for storing a map.
   *
   * @param string $token
   *
   * @return string
   */
  private static function prepareMapStorageKey(string $token): string
  {
    return 'map:' . $token;
  }
}
