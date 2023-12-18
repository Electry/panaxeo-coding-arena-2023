<?php declare(strict_types = 1);

use Electry\Battleships\Model\Engine\Enums\ShapeType;
use Electry\Battleships\Model\Engine\Map;
use Electry\Battleships\Bootstrap;
use Tracy\ILogger;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Dirty script to check and dump shape type bias grid based on previous submit data.
 *
 * docker exec battleships php -r 'opcache_reset();'
 * docker exec -it battleships php -dopcache.enable_cli=1 -dopcache.jit_buffer_size=1024M bin/tools/check_bias_grid.php
 */
$games = 9;

function getRealGameData() {
  global $games;

  $data = [];
  $rot = [];

  for ($i = 0; $i < 200; $i++) {
    for ($game = 0; $game < $games; $game++) {
      $map = Map::createFromRealGameData('submit_' . $game . '.data', $i);

      foreach ($map->battleships as $battleship) {
        foreach ($battleship->getShipCellCoordinates() as [$x, $y]) {
          $data[$battleship->shape->getShapeType()->value][$x][$y] ??= 0;
          $data[$battleship->shape->getShapeType()->value][$x][$y]++;
        }
        $rot[$battleship->rotation->value] ??= 0;
        $rot[$battleship->rotation->value]++;
      }
    }
  }

  for ($x = 0; $x < 12; $x++) {
    for ($y = 0; $y < 12; $y++) {
      foreach ($data as $shapeTypeValue => $d) {
        $data[$shapeTypeValue][$x][$y] /= ($games * 200);
      }
    }
  }

  return $data;
}

function getGenOutData() {
  $jsonString = file_get_contents(__DIR__ . '/gen_out.log');
  $json = json_decode($jsonString, true);

  for ($x = 0; $x < 12; $x++) {
    for ($y = 0; $y < 12; $y++) {
      foreach ($json as $shapeTypeValue => $d) {
        $json[$shapeTypeValue][$x][$y] /= 100000;
      }
    }
  }

  return $json;
}

function getMultiplier(array $d1, array $d2) {
  $data = [];

  for ($x = 0; $x < 12; $x++) {
    for ($y = 0; $y < 12; $y++) {
      foreach (ShapeType::cases() as $shapeType) {
        if ($d2[$shapeType->value][$x][$y] > 0) {
          $data[$shapeType->value][$x][$y] = $d1[$shapeType->value][$x][$y] / $d2[$shapeType->value][$x][$y];
        } else {
          $data[$shapeType->value][$x][$y] = 0.01;
        }
      }
    }
  }

  $h = fopen(__DIR__ . '/bias.log', 'w');
  fwrite($h, json_encode($data));
  fclose($h);

  return $data;
}

function printData($data) {
    $str = '';

    for ($y = 0; $y < 12; $y++) {
      for ($x = 0; $x < 12; $x++) {
        $str .= '     ' . ($data[ShapeType::PATROL_BOAT->value][$x][$y] ?? 0) . ' ';
      }

      $str .= PHP_EOL;
    }

    echo $str;
    return $str;
}

printData(getMultiplier(getRealGameData(), getGenOutData()));
