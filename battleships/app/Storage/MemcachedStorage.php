<?php declare(strict_types = 1);

namespace Electry\Battleships\Storage;

use Electry\Battleships\Exceptions\NotFoundException;
use Electry\Battleships\Exceptions\SystemException;
use Memcached;

/**
 * Storage provider using the {@see Memcached} backend.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final class MemcachedStorage implements IStorage
{
  /** @var Memcached Memcached backend. */
  private Memcached $memcache;

  /**
   * Constructor.
   *
   * @param string $host
   * @param int    $port
   *
   * @throws SystemException
   */
  public function __construct(string $host, int $port)
  {
    $this->memcache = new Memcached();
    $connected = $this->memcache->addServer($host, $port);

    if (!$connected) {
      $resultCode = $this->memcache->getResultCode();
      throw new SystemException('Failed to connect to memcached, code: ' . $resultCode);
    }
  }

  /** @inheritDoc */
  public function set(string $key, string $data): void
  {
    $result = $this->memcache->set($key, $data);
    if ($result === false) {
      $resultCode = $this->memcache->getResultCode();
      throw new SystemException('Failed to store data at given key: ' . $key . ', code: ' . $resultCode);
    }
  }

  /** @inheritDoc */
  public function get(string $key): string
  {
    $result = $this->memcache->get($key);

    if ($result === false) {
      $resultCode = $this->memcache->getResultCode();
      if ($resultCode === Memcached::RES_NOTFOUND) {
        throw new NotFoundException('No data at given key: ' . $key);
      }

      throw new SystemException('Failed to obtain data at given key: ' . $key . ', code: ' . $resultCode);
    }

    return $result;
  }

  /** @inheritDoc */
  public function delete(string $key): bool
  {
    $result = $this->memcache->delete($key);
    if ($result === false) {
      $resultCode = $this->memcache->getResultCode();
      if ($resultCode === Memcached::RES_NOTFOUND) {
        return false;
      }

      throw new SystemException('Failed to delete data at given key: ' . $key . ', code: ' . $resultCode);
    }

    return true;
  }

  /** @inheritDoc */
  public function flush(): void
  {
    $result = $this->memcache->flush();
    if ($result === false) {
      $resultCode = $this->memcache->getResultCode();
      throw new SystemException('Failed to flush data, code: ' . $resultCode);
    }
  }
}
