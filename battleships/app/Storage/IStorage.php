<?php declare(strict_types = 1);

namespace Electry\Battleships\Storage;

use Electry\Battleships\Exceptions\NotFoundException;
use Electry\Battleships\Exceptions\SystemException;

/**
 * Interface for a data storage provider.
 *
 * @copyright (C) 2023 Electry Solutions
 * @author        Michal Chvila
 * @since         2023-11-28
 */
interface IStorage
{
  /**
   * Store data with given key.
   *
   * @param string $key
   * @param string $data
   *
   * @return void
   * @throws SystemException Failed to store data.
   */
  public function set(string $key, string $data): void;

  /**
   * Return stored data with given key.
   *
   * @param string $key
   *
   * @return string
   * @throws NotFoundException Data not found in the storage.
   * @throws SystemException Failed to obtain data.
   */
  public function get(string $key): string;

  /**
   * Delete data with given key.
   *
   * @param string $key
   *
   * @return bool True if any data was deleted, false otherwise.
   * @throws SystemException Failed to delete data.
   */
  public function delete(string $key): bool;

  /**
   * Delete all data.
   *
   * @return void
   * @throws SystemException Failed to delete data.
   */
  public function flush(): void;
}
