<?php declare(strict_types = 1);

namespace Electry\Battleships\Bot;

use Electry\Battleships\Exceptions\DataException;
use Electry\Battleships\Exceptions\SystemException;
use Electry\Battleships\Responses\AvengerFireResponse;
use Electry\Battleships\Responses\FireResponse;
use Electry\Battleships\Model\Engine\Enums\Avenger;

/**
 * Interface for an API client.
 *
 * @copyright (C) 2023 Electry Solutions
 * @author        Michal Chvila
 * @since         2023-12-01
 */
interface IApiClient
{
  /**
   * Send request to the /fire endpoint (to obtain current map status).
   *
   * @return FireResponse
   * @throws SystemException
   * @throws DataException
   */
  public function status(): FireResponse;

  /**
   * Send request to the /fire/<y>/<x> endpoint (to discover new cell).
   *
   * @param int $x
   * @param int $y
   *
   * @return FireResponse
   * @throws SystemException
   * @throws DataException
   */
   public function fire(int $x, int $y): FireResponse;

  /**
   * Send request to the /fire/<y>/<x>/avenger/<avenger> endpoint
   * (to discover new cell with the use of an avenger ability).
   *
   * @param int     $x
   * @param int     $y
   * @param Avenger $avenger
   *
   * @return AvengerFireResponse
   * @throws SystemException
   * @throws DataException
   */
  public function fireAvenger(int $x, int $y, Avenger $avenger): AvengerFireResponse;
}
