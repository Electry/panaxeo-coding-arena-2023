<?php declare(strict_types = 1);

namespace Electry\Battleships\Presenters;

use Exception;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Http\IRequest;
use Nette\Http\IResponse;

/**
 * Base presenter with authentication.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
abstract class APresenter implements IPresenter
{
  /** @var IRequest HTTP request. */
  protected IRequest $httpRequest;

  /** @var IResponse HTTP response. */
  protected IResponse $httpResponse;

  /** @var string Authorization token. */
  protected string $token;

  /**
   * Inject HTTP request & response.
   *
   * @param IRequest  $httpRequest
   * @param IResponse $httpResponse
   *
   * @return void
   */
  public function injectHttpRequestResponse(IRequest $httpRequest, IResponse $httpResponse): void
  {
    $this->httpRequest = $httpRequest;
    $this->httpResponse = $httpResponse;
  }

  /**
   * Handle request, authenticate user and return a response.
   *
   * @param Request $request
   *
   * @return Response
   * @throws BadRequestException
   * @throws Exception
   */
  final public function run(Request $request): Response
  {
    if ($request->getMethod() !== 'GET') {
      throw new BadRequestException('Invalid http method', 400);
    }

    $token = $this->getToken($request);
    if ($token === null) {
      // https://www.panaxeo.com/coding-arena/#api
      // {"error": "Unauthorized"}
      throw new BadRequestException('Unauthorized', 403);
    }

    $this->token = $token;
    return $this->handle($request);
  }

  /**
   * Obtain the token from the HTTP/application request.
   *
   * @param Request $request
   *
   * @return string|null
   */
  private function getToken(Request $request): ?string
  {
    $authorization = $this->httpRequest->getHeader('Authorization');
    if ($authorization !== null && str_starts_with($authorization, 'Bearer ')) {
      return substr($authorization, 7);
    }

    return $request->getParameter('token');
  }

  /**
   * Handle request of authenticated user.
   *
   * @param Request $request
   *
   * @return Response
   * @throws BadRequestException
   * @throws Exception
   */
  abstract protected function handle(Request $request): Response;
}
