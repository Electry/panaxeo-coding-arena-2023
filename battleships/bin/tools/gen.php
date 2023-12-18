<?php declare(strict_types = 1);

use Electry\Battleships\Model\Engine\Map;
use Tracy\ILogger;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Dirty script to dump 100% randomized ship cell occurrences for each shape type.
 *
 * docker exec battleships php -r 'opcache_reset();'
 * docker exec -it battleships php -dopcache.enable_cli=1 -dopcache.jit_buffer_size=1024M bin/tools/gen.php
 */
$maps = 100000;

$data = [];

for ($i = 0; $i < $maps; $i++) {
  $map = Map::createNew($i, 12, 12);

  foreach ($map->battleships as $battleship) {
    foreach ($battleship->getShipCellCoordinates() as [$x, $y]) {
      $data[$battleship->shape->getShapeType()->value][$x][$y] ??= 0;
      $data[$battleship->shape->getShapeType()->value][$x][$y]++;
    }
  }
  echo $i . PHP_EOL;
}

$str = json_encode($data);
$handle = fopen(__DIR__ . '/gen_out.log', 'w');
fwrite($handle, $str);
fclose($handle);
