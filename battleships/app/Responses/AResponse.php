<?php declare(strict_types = 1);

namespace Electry\Battleships\Responses;

use Electry\Battleships\Exceptions\DataException;
use JsonSerializable;

/**
 * Base for all responses.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-12-01
 */
readonly abstract class AResponse implements JsonSerializable
{
  /**
   * Validate that data contains a field.
   *
   * @param array<string, mixed> $data
   * @param string               $field
   *
   * @return void
   * @throws DataException
   */
  protected static function validateDataField(array $data, string $field): void
  {
    if (!isset($data[$field])) {
      throw new DataException('Missing field: ' . $field);
    }
  }

  /**
   * Validate that data contains all field.
   *
   * @param array<string, mixed> $data
   * @param string[]             $fields
   *
   * @return void
   * @throws DataException
   */
  protected static function validateDataFields(array $data, array $fields): void
  {
    foreach ($fields as $field) {
      self::validateDataField($data, $field);
    }
  }
}
