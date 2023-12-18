<?php declare(strict_types = 1);

use Electry\Battleships\Model\Engine\Enums\Rotation;
use Electry\Battleships\Model\Engine\Map;
use Electry\Battleships\Bootstrap;
use Tracy\ILogger;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Dirty script to check and dump x=0 & y=0 battleship occurrences w/ rotations based on previous submit data.
 *
 * docker exec battleships php -r 'opcache_reset();'
 * docker exec -it battleships php -dopcache.enable_cli=1 -dopcache.jit_buffer_size=1024M bin/tools/check_rotations.php
 */
$games = 9;

$x0 = [];
$y0 = [];

for ($i = 0; $i < 200; $i++) {
  for ($game = 0; $game < $games; $game++) {
    $map = Map::createFromRealGameData('submit_' . $game . '.data', $i);

    foreach ($map->battleships as $battleship) {
      if ($battleship->x === 0 && $battleship->y !== 0) {
        $x0[$battleship->rotation->value]++;
      }
      if ($battleship->x !== 0 && $battleship->y === 0) {
        $y0[$battleship->rotation->value]++;
      }
    }
  }
}

var_dump($x0);
var_dump($y0);
var_dump($x0[Rotation::HORIZONTAL->value] / $x0[Rotation::VERTICAL->value]);
var_dump($y0[Rotation::VERTICAL->value] / $y0[Rotation::HORIZONTAL->value]);
