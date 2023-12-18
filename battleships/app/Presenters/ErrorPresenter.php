<?php declare(strict_types = 1);

namespace Electry\Battleships\Presenters;

use Nette\Application\BadRequestException;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Http\IResponse;
use Tracy\ILogger;

/**
 * ErrorPresenter.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final readonly class ErrorPresenter implements IPresenter
{
  /**
   * Constructor.
   *
   * @param ILogger   $logger
   * @param IResponse $httpResponse
   */
	public function __construct(
    private ILogger $logger,
    private IResponse $httpResponse
  )
	{
	}

  /**
   * Return an error response for given request.
   *
   * @param Request $request
   *
   * @return Response
   */
	public function run(Request $request): Response
	{
		$exception = $request->getParameter('exception');

    $httpCode = 500;
    $message = $exception->getMessage();

    if($exception instanceof BadRequestException)
    {
      $httpCode = $exception->getHttpCode();
    }
    else
    {
      $this->logger->log($exception, ILogger::EXCEPTION);
    }

    $this->httpResponse->setCode($httpCode);
    return new JsonResponse(['error' => $message]);
	}
}
