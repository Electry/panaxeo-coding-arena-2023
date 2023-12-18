<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Services;

use Electry\Battleships\Exceptions\NotFoundException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Model\Data\UserData;
use Electry\Battleships\Storage\IStorage;
use JsonException;

/**
 * Service for managing {@see UserData} and games played by the player.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-29
 */
final readonly class UserService
{
  /** @var int Maximum number of games that the player is allowed to play. */
  public const int MAX_ATTEMPTS = 9999;

  /** @var int How many games does the player need to finish in one single game. */
  public const int MAP_COUNT_IN_GAME = 200;

  /**
   * Constructor.
   *
   * @param IStorage $storage
   */
  public function __construct(private IStorage $storage)
  {
  }

  /**
   * Get or create new user data with default values.
   *
   * @param string $token
   *
   * @return UserData
   * @throws SystemException
   */
  public function getOrCreateUserData(string $token): UserData
  {
    try {
      $serializedData = $this->storage->get(self::prepareUserStorageKey($token));

      try {
        $data = json_decode($serializedData, true, 512, JSON_THROW_ON_ERROR);
      } catch (JsonException $e) {
        throw new SystemException('Failed to decode json data: ' . $e->getMessage(), $e->getCode(), $e);
      }

      return UserData::jsonUnserialize($data);
    } catch (NotFoundException) {
      $userData = new UserData(
        attempts: 0,
        lastMapId: null,
        remainingMapCountInGame: self::MAP_COUNT_IN_GAME,
        bestScore: null,
        currentGameScore: null
      );

      $this->saveUserData($token, $userData);
      return $userData;
    }
  }

  /**
   * Save user data.
   *
   * @param string   $token
   * @param UserData $userData
   *
   * @return void
   * @throws SystemException
   */
  public function saveUserData(string $token, UserData $userData): void
  {
    try {
      $serializedData = json_encode($userData, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new SystemException('Failed to serialize map data: ' . $e->getMessage(), $e->getCode(), $e);
    }

    $this->storage->set(self::prepareUserStorageKey($token), $serializedData);
  }

  /**
   * Generate map id for the next map in sequence, and store it in user data.
   *
   * @param string   $token
   * @param UserData $userData
   *
   * @return int
   * @throws SystemException
   */
  public function generateNewMapId(string $token, UserData $userData): int
  {
    if ($userData->lastMapId === null) {
      $userData->lastMapId = 1000;
    } else {
      $userData->lastMapId++;
    }

    $this->saveUserData($token, $userData);
    return $userData->lastMapId;
  }

  /**
   * Delete user data.
   *
   * @param string $token
   *
   * @return bool
   * @throws SystemException
   */
  public function deleteUserData(string $token): bool
  {
    return $this->storage->delete(self::prepareUserStorageKey($token));
  }

  /**
   * Increase the number of played games (attempts) and save the current score if needed.
   * This does NOT validate whether the player exceeded the maximum games allowed,
   * or whether the accumulated score is complete.
   *
   * @param string   $token
   * @param UserData $userData
   * @param bool     $saveScore True if the current game was successfully finished and thus score should be saved.
   *
   * @return bool
   * @throws SystemException
   */
  public function playNewGame(string $token, UserData $userData, bool $saveScore = false): bool
  {
    if ($saveScore && $userData->currentGameScore !== null) {
      if ($userData->bestScore === null || $userData->bestScore < $userData->currentGameScore) {
        $userData->bestScore = $userData->currentGameScore;
      }
    }

    $userData->currentGameScore = 0;
    $userData->remainingMapCountInGame = self::MAP_COUNT_IN_GAME;
    $userData->attempts++;

    $this->saveUserData($token, $userData);
    return true;
  }

  /**
   * Prepare key for storing user data.
   *
   * @param string $token
   *
   * @return string
   */
  private static function prepareUserStorageKey(string $token): string
  {
    return 'user:' . $token;
  }
}
