<?php declare(strict_types = 1);

namespace Electry\Battleships;

use Nette\Bootstrap\Configurator;

/**
 * Application bootstrap.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final class Bootstrap
{
  /**
   * Boot up the application.
   *
   * @return Configurator
   */
  public static function boot(): Configurator
  {
    $configurator = new Configurator();
    $appDir = dirname(__DIR__);

    // $configurator->setDebugMode(true);
    $configurator->setDebugMode(false);
    $configurator->enableTracy($appDir . '/log');

    $configurator->setTimeZone('Europe/Bratislava');
    $configurator->setTempDirectory($appDir . '/temp');

    $configurator->addConfig($appDir . '/config/common.neon');
    $configurator->addConfig($appDir . '/config/services.neon');
    $configurator->addConfig($appDir . '/config/local.neon');

    return $configurator;
  }
}
