<?php declare(strict_types = 1);

namespace Electry\Battleships\Presenters;

use Electry\Battleships\Exceptions\Engine\EngineException;
use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Exceptions\NotFoundException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Model\Data\UserData;
use Electry\Battleships\Model\Engine\Enums\Avenger;
use Electry\Battleships\Model\Engine\Map;
use Electry\Battleships\Model\Facades\FireFacade;
use Electry\Battleships\Model\Services\MapService;
use Electry\Battleships\Model\Services\UserService;
use Electry\Battleships\Storage\IStorage;
use Nette\Application\BadRequestException;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Bridges\ApplicationLatte\LatteFactory;

/**
 * Primary endpoint.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final class FirePresenter extends APresenter
{
  /**
   * Constructor.
   *
   * @param LatteFactory $latteFactory
   * @param UserService  $userService
   * @param MapService   $mapService
   * @param FireFacade   $facade
   * @param IStorage     $storage
   */
  public function __construct(
    private readonly LatteFactory $latteFactory,
    private readonly UserService $userService,
    private readonly MapService $mapService,
    private readonly FireFacade $facade,
    private readonly IStorage $storage
  )
  {
  }

  /** @inheritDoc */
  public function handle(Request $request): Response
  {
    $action = $request->getParameter('action');

    return match ($action) {
      'status' => $this->status($request),
      'fire' => $this->fire($request),
      'fireAvenger' => $this->fireAvenger($request),
      default => throw new BadRequestException('Invalid action', 404)
    };
  }

  /**
   * Getting the status of an ongoing game.
   *
   * @param Request $request
   *
   * @return Response
   * @throws BadRequestException
   * @throws EngineException
   * @throws FatalException
   * @throws NotFoundException
   * @throws OutOfBoundsException
   * @throws SystemException
   */
  private function status(Request $request): Response
  {
    $fireResponse = $this->facade->processStatusAction($this->token);

    // Draw HTML instead of responding with json, if asked for
    if ($request->getParameter('draw') !== null) {
      $userData = $this->userService->getOrCreateUserData($this->token);
      $map = $this->mapService->getMap($this->token);
      return $this->getDrawResponse($userData, $map);
    }

    return new JsonResponse($fireResponse);
  }

  /**
   * Firing at specified position.
   *
   * @param Request $request
   *
   * @return Response
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   * @throws BadRequestException
   */
  private function fire(Request $request): Response
  {
    [$x, $y] = self::getColumnAndRow($request);

    $fireResponse = $this->facade->processFireAction($this->token, $x, $y);
    return new JsonResponse($fireResponse);
  }

  /**
   * Firing at specified position with help of avenger.
   *
   * @param Request $request
   *
   * @return Response
   * @throws BadRequestException
   * @throws EngineException
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   */
  private function fireAvenger(Request $request): Response
  {
    [$x, $y] = self::getColumnAndRow($request);
    $avenger = self::getAvenger($request);

    $avengerFireResponse = $this->facade->processFireAvengerAction($this->token, $x, $y, $avenger);
    return new JsonResponse($avengerFireResponse);
  }

  /**
   * Render HTML with current map grid and additional data.
   *
   * @param UserData $userData
   * @param Map      $map
   *
   * @return Response
   * @throws FatalException
   * @throws OutOfBoundsException
   * @throws SystemException
   */
  private function getDrawResponse(UserData $userData, Map $map): Response
  {
    $messages = [];
    $messages[] = 'Attempts: ' . $userData->attempts;
    $messages[] = 'Remaining map count: ' . $userData->remainingMapCountInGame;
    $messages[] = 'Last map id: ' . $userData->lastMapId;
    $messages[] = 'Move count: ' . $map->getMoveCount();
    $messages[] = 'Current game score (incomplete): ' . $userData->currentGameScore;
    $messages[] = 'Best score: ' . $userData->bestScore;
    $messages[] = 'Discovered coordinates: ' . json_encode($map->getDiscoveredCellCoordinates());

    try {
      $aiMap = $this->storage->get('ai_map_' . $map->getId());
      $messages[] = 'AI Map: ' . $aiMap;
    } catch (NotFoundException) {
    }

    $latte = $this->latteFactory->create();
    $html = $latte->renderToString(
      __DIR__ . '/templates/draw.latte',
      [
        'messages' => $messages,
        'map' => $map
      ]
    );

    return new TextResponse($html);
  }

  /**
   * Parse the request params into [x, y] coordinates.
   *
   * @param Request $request
   *
   * @return array{0: int, 1: int} [x, y].
   * @throws BadRequestException
   */
  private static function getColumnAndRow(Request $request): array
  {
    $row = $request->getParameter('row');
    $column = $request->getParameter('column');

    if (!is_numeric($row) || !is_numeric($column)) {
      throw new BadRequestException('Invalid values for row or column');
    }

    $x = (int) $column;
    $y = (int) $row;

    return [$x, $y];
  }

  /**
   * Parse the request param for the avenger.
   *
   * @param Request $request
   *
   * @return Avenger
   * @throws BadRequestException
   */
  private static function getAvenger(Request $request): Avenger
  {
    $avenger = Avenger::tryFrom($request->getParameter('avenger') ?? '');
    if ($avenger === null) {
      throw new BadRequestException('Invalid value for avenger');
    }

    return $avenger;
  }
}
