<?php declare(strict_types = 1);

namespace Electry\Battleships\Bot;

use Electry\Battleships\Exceptions\DataException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Model\Engine\Enums\Avenger;
use Electry\Battleships\Responses\AvengerFireResponse;
use Electry\Battleships\Responses\FireResponse;
use JsonException;
use Tracy\ILogger;

/**
 * HTTP API client.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-12-01
 */
final readonly class HttpApiClient implements IApiClient
{
  /**
   * Constructor.
   *
   * @param ILogger $logger
   * @param string  $baseUrl Base API url (should NOT include trailing slash).
   * @param string  $token   Player token.
   * @param bool    $test
   */
  public function __construct(
    private ILogger $logger,
    private string $baseUrl,
    private string $token,
    private bool $test
  )
  {
  }

  /** @inheritDoc */
  public function status(): FireResponse
  {
    $this->logger->log('<FireRequest ep="status" test="' . ($this->test ? 'yes' : 'no') . '"/>');
    $body = $this->sendApiRequest('/fire' . ($this->test ? '?test=yes' : ''));
    $response = self::parseFireResponse($body);
    $this->logger->log((string) $response);
    return $response;
  }

  /** @inheritDoc */
  public function fire(int $x, int $y): FireResponse
  {
    $this->logger->log('<FireRequest ep="fire" test="' . ($this->test ? 'yes' : 'no') . '" x="' . $x . ' y="' . $y . '"/>');
    $body = $this->sendApiRequest('/fire/' . $y . '/' . $x . ($this->test ? '?test=yes' : ''));
    $response = self::parseFireResponse($body);
    $this->logger->log((string) $response);
    return $response;
  }

  /** @inheritDoc */
  public function fireAvenger(int $x, int $y, Avenger $avenger): AvengerFireResponse
  {
    $this->logger->log('<AvengerFireRequest ep="fire_avenger" test="' . ($this->test ? 'yes' : 'no') . '" x="' . $x . ' y="' . $y . '" avenger="' . $avenger->value . '"/>');
    $body = $this->sendApiRequest('/fire/' . $y . '/' . $x . '/avenger/' . $avenger->value . ($this->test ? '?test=yes' : ''));
    $response = self::parseAvengerFireResponse($body);
    $this->logger->log((string) $response);
    return $response;
  }

  /**
   * Send an API request to given endpoint.
   *
   * @param string $ep
   *
   * @return string Response body.
   * @throws SystemException
   */
  private function sendApiRequest(string $ep): string
  {
    $ch = curl_init();
    $headers = [
      'Accept: application/json',
      'Authorization: Bearer ' . $this->token,
    ];

    curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $ep);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, 0);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $body = curl_exec($ch);
    if ($body === false) {
      throw new SystemException('Failed to obtain response body: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($httpCode !== 200) {
      throw new SystemException('Received HTTP code: ' . $httpCode . ', response body: ' . $body);
    }

    return $body;
  }

  /**
   * Parse {@see FireResponse} from given json response body.
   *
   * @param string $body
   *
   * @return FireResponse
   * @throws SystemException
   * @throws DataException
   */
  private static function parseFireResponse(string $body): FireResponse
  {
    try {
      $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new SystemException('Failed to parse json body: ' . $body, $e->getCode(), $e);
    }

    return FireResponse::jsonUnserialize($data);
  }

  /**
   * Parse {@see AvengerFireResponse} from given json response body.
   *
   * @param string $body
   *
   * @return AvengerFireResponse
   * @throws SystemException
   * @throws DataException
   */
  private static function parseAvengerFireResponse(string $body): AvengerFireResponse
  {
    try {
      $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new SystemException('Failed to parse json body: ' . $body, $e->getCode(), $e);
    }

    return AvengerFireResponse::jsonUnserialize($data);
  }
}
