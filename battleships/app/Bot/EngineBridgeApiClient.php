<?php declare(strict_types = 1);

namespace Electry\Battleships\Bot;

use Electry\Battleships\Exceptions\Engine\EngineException;
use Electry\Battleships\Exceptions\Engine\FatalException;
use Electry\Battleships\Exceptions\Engine\OutOfBoundsException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Model\Engine\Enums\Avenger;
use Electry\Battleships\Model\Facades\FireFacade;
use Electry\Battleships\Responses\AvengerFireResponse;
use Electry\Battleships\Responses\FireResponse;
use Nette\Application\BadRequestException;
use Nette\DI\Container;
use Tracy\ILogger;

/**
 * Engine bridge.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-12-01
 */
final class EngineBridgeApiClient implements IApiClient
{
  /** @var FireFacade Facade. */
  private FireFacade $facade;

  /** @var ILogger Logger. */
  private ILogger $logger;

  /**
   * Constructor.
   *
   * @param Container $container Nette DI application container.
   * @param string    $token     Player token.
   */
  public function __construct(Container $container, private readonly string $token)
  {
    $this->facade = $container->getByType(FireFacade::class);
    $this->logger = $container->getByType(ILogger::class);
  }

  /** @inheritDoc */
  public function status(): FireResponse
  {
    try {
      // $this->logger->log('<FireRequest ep="status"/>');
      $response = $this->facade->processStatusAction($this->token);
      // $this->logger->log((string) $response);
      return $response;
    } catch (FatalException | OutOfBoundsException | EngineException | BadRequestException | SystemException $e) {
      throw new SystemException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /** @inheritDoc */
  public function fire(int $x, int $y): FireResponse
  {
    try {
      // $this->logger->log('<FireRequest ep="fire" x="' . $x . ' y="' . $y . '"/>');
      $response = $this->facade->processFireAction($this->token, $x, $y);
      // $this->logger->log((string) $response);
      return $response;
    } catch (FatalException | OutOfBoundsException | EngineException | BadRequestException | SystemException $e) {
      throw new SystemException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /** @inheritDoc */
  public function fireAvenger(int $x, int $y, Avenger $avenger): AvengerFireResponse
  {
    try {
      // $this->logger->log('<AvengerFireRequest ep="fire_avenger" x="' . $x . ' y="' . $y . '" avenger="' . $avenger->value . '"/>');
      $response = $this->facade->processFireAvengerAction($this->token, $x, $y, $avenger);
      // $this->logger->log((string) $response);
      return $response;
    } catch (FatalException | OutOfBoundsException | EngineException | BadRequestException | SystemException $e) {
      throw new SystemException($e->getMessage(), $e->getCode(), $e);
    }
  }
}
