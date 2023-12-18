<?php declare(strict_types = 1);

namespace Electry\Battleships\Presenters;

use Electry\Battleships\Model\Services\MapService;
use Electry\Battleships\Model\Services\UserService;
use Electry\Battleships\Storage\IStorage;
use Nette\Application\BadRequestException;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;

/**
 * Reset endpoint.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final class ResetPresenter extends APresenter
{
  /**
   * Constructor.
   *
   * @param IStorage    $storage
   * @param UserService $userService
   * @param MapService  $mapService
   */
  public function __construct(
    private readonly IStorage $storage,
    private readonly UserService $userService,
    private readonly MapService $mapService
  )
  {
  }

  /** @inheritDoc */
  public function handle(Request $request): Response
  {
    // Special request to wipe all data from storage for given token
    if ($request->getParameter('wipe') !== null) {
      $this->userService->deleteUserData($this->token);
      $this->mapService->deleteMap($this->token);
      $this->storage->flush();
      return new JsonResponse('ok');
    }

    $userData = $this->userService->getOrCreateUserData($this->token);

    if ($userData->attempts >= UserService::MAX_ATTEMPTS) {
      throw new BadRequestException('Max tries already reached');
    }

    if (!$this->mapService->deleteMap($this->token)) {
      throw new BadRequestException('No ongoing game found');
    }

    $this->userService->playNewGame($this->token, $userData);
    return new JsonResponse(['availableTries' => UserService::MAX_ATTEMPTS - $userData->attempts]);
  }
}
