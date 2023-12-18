<?php declare(strict_types = 1);

namespace Electry\Battleships\Model\Facades;

use Electry\Battleships\Exceptions\DataException;
use Electry\Battleships\Exceptions\Engine\EngineException;
use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Exceptions\NotFoundException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Model\Data\UserData;
use Electry\Battleships\Model\Engine\Data\AvengerResultData;
use Electry\Battleships\Model\Engine\Enums\Avenger;
use Electry\Battleships\Model\Engine\Map;
use Electry\Battleships\Model\Services\MapService;
use Electry\Battleships\Model\Services\UserService;
use Electry\Battleships\Responses\AvengerFireResponse;
use Electry\Battleships\Responses\FireResponse;
use Nette\Application\BadRequestException;

/**
 * Fire facade.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-29
 */
final readonly class FireFacade
{
  /**
   * Constructor.
   *
   * @param UserService  $userService
   * @param MapService   $mapService
   */
  public function __construct(
    private UserService $userService,
    private MapService $mapService
  )
  {
  }

  /**
   * Getting the status of an ongoing game.
   *
   * @param string $token
   *
   * @return FireResponse
   * @throws BadRequestException
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   */
  public function processStatusAction(string $token): FireResponse
  {
    $userData = $this->userService->getOrCreateUserData($token);
    $map = $this->getMapForNextAction($token, $userData);

    return new FireResponse(
      grid: (string) $map,
      cell: '',
      result: false,
      avengerAvailable: $map->isAvengerAvailable(),
      mapId: $map->getId(),
      mapCount: $userData->remainingMapCountInGame,
      moveCount: $map->getMoveCount(),
      finished: $userData->remainingMapCountInGame === 0
    );
  }

  /**
   * Firing at specified position.
   *
   * @param string $token
   * @param int    $x
   * @param int    $y
   *
   * @return FireResponse
   * @throws BadRequestException
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   */
  public function processFireAction(string $token, int $x, int $y): FireResponse
  {
    $userData = $this->userService->getOrCreateUserData($token);
    $map = $this->getMapForNextAction($token, $userData);
    $cell = null;

    try {
      $cellWasNotDiscoveredBefore = !$map->isCellDiscovered($x, $y);
      if ($cellWasNotDiscoveredBefore) {
        $cell = $map->fire($x, $y);
      }
    } catch (OutOfBoundsException) {
      throw new BadRequestException('Invalid values for row or column');
    }

    $this->mapService->saveMap($token, $map);

    // End current map if all the battleships were destroyed by this action
    $this->processMapEndState($token, $userData, $map);

    return new FireResponse(
      grid: (string) $map,
      cell: $cell?->value ?? '',
      result: $cellWasNotDiscoveredBefore,
      avengerAvailable: $map->isAvengerAvailable(),
      mapId: $map->getId(),
      mapCount: $userData->remainingMapCountInGame,
      moveCount: $map->getMoveCount(),
      finished: $userData->remainingMapCountInGame === 0
    );
  }

  /**
   * Firing at specified position with help of avenger.
   *
   * @param string  $token
   * @param int     $x
   * @param int     $y
   * @param Avenger $avenger
   *
   * @return AvengerFireResponse
   * @throws BadRequestException
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   */
  public function processFireAvengerAction(string $token, int $x, int $y, Avenger $avenger): AvengerFireResponse
  {
    $userData = $this->userService->getOrCreateUserData($token);
    $map = $this->getMapForNextAction($token, $userData);
    $cell = null;

    /** @var AvengerResultData[] $avengerResults */
    $avengerResults = [];

    try {
      $cellWasNotDiscoveredBefore = !$map->isCellDiscovered($x, $y);
      if ($cellWasNotDiscoveredBefore) {
        $cell = $map->fireAvenger($x, $y, $avenger, $avengerResults);
      }
    } catch (OutOfBoundsException) {
      throw new BadRequestException('Invalid values for row or column');
    }

    $this->mapService->saveMap($token, $map);

    // End current map if all the battleships were destroyed by this action
    $this->processMapEndState($token, $userData, $map);

    // Transform avenger results into the defined response
    $avengerResultInResponse = [];
    foreach ($avengerResults as $avengerResult) {
      $avengerResultInResponse[] = [
        'mapPoint' => [
          'x' => $avengerResult->row,
          'y' => $avengerResult->column
        ],
        'hit' => $avengerResult->hit
      ];
    }

    return new AvengerFireResponse(
      grid: (string) $map,
      cell: $cell?->value ?? '',
      result: $cellWasNotDiscoveredBefore,
      avengerAvailable: $map->isAvengerAvailable(),
      mapId: $map->getId(),
      mapCount: $userData->remainingMapCountInGame,
      moveCount: $map->getMoveCount(),
      finished: $userData->remainingMapCountInGame === 0,
      avengerResult: $avengerResultInResponse
    );
  }

  /**
   * Obtain current or create new map for the action that is being handled.
   *
   * @param string   $token
   * @param UserData $userData
   *
   * @return Map
   * @throws BadRequestException If new map cannot be created due to max. game attempts reached.
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   * @throws DataException
   */
  private function getMapForNextAction(string $token, UserData $userData): Map
  {
    // If player has finished all maps in the current game
    if ($userData->remainingMapCountInGame === 0) {
      if ($userData->attempts >= UserService::MAX_ATTEMPTS) {
        throw new BadRequestException('Max tries already reached');
      }

      $this->mapService->deleteMap($token); // just in case (should be deleted during the last fire action on the previous map)

      // Raise the attempts counter and save the score
      $this->userService->playNewGame($token, $userData, true);
    }

    try {
      $map = $this->mapService->getMap($token);
    } catch (NotFoundException) {
      $newMapId = $this->userService->generateNewMapId($token, $userData);
      $map = $this->mapService->createMap($token, $newMapId);
    }

    return $map;
  }

  /**
   * Check if all the battleships were discovered on the map.
   *
   * @param string   $token
   * @param UserData $userData
   * @param Map      $map
   *
   * @return void
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   */
  private function processMapEndState(string $token, UserData $userData, Map $map): void
  {
    if (!$map->areAllBattleshipsFullyDiscovered()) {
      return;
    }

    $userData->currentGameScore ??= 0;
    $userData->currentGameScore += $map->getMoveCount();

    $this->mapService->deleteMap($token);

    $userData->remainingMapCountInGame--;
    $this->userService->saveUserData($token, $userData);
  }
}
