<?php declare(strict_types = 1);

namespace Electry\Battleships\Router;

use Nette\Application\Routers\RouteList;
use Nette\StaticClass;

/**
 * Application router factory.
 *
 * @copyright 2023 Electry Solutions
 * @author    Michal Chvila
 * @since     2023-11-28
 */
final class RouterFactory
{
	use StaticClass;

  /**
   * Create static route list.
   *
   * @return RouteList
   */
	public static function createRouter(): RouteList
	{
		$router = new RouteList();

    // Getting the status of an ongoing game
		$router->addRoute('fire', 'Fire:status');

    // Firing at specified position
		$router->addRoute('fire/[<row \d+>]/[<column \d+>]', 'Fire:fire');

    // Firing at specified position with help of avenger
		$router->addRoute('fire/[<row \d+>]/[<column \d+>]/avenger/<avenger>', 'Fire:fireAvenger');

    // Reset ongoing game
    $router->addRoute('reset', 'Reset:default');

		return $router;
	}
}
