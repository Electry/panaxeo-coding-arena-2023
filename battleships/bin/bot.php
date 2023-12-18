<?php declare(strict_types = 1);

use Electry\Battleships\Bot\Bot;
use Electry\Battleships\Bot\EngineBridgeApiClient;
use Electry\Battleships\Bot\HttpApiClient;
use Electry\Battleships\Bootstrap;
use Electry\Battleships\Storage\IStorage;
use Tracy\ILogger;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Prepare:
 *    rm log/info.txt
 *    docker exec battleships php -r 'opcache_reset();'
 *
 *  Run:
 *    docker exec -it battleships php -dopcache.enable_cli=1 -dopcache.jit_buffer_size=1024M bin/bot.php
 *
 *  Run in the background:
 *    nohup docker exec battleships php -dopcache.enable_cli=1 -dopcache.jit_buffer_size=1024M bin/bot.php > cur.log 2>&1 &
 *    tail --follow cur.log
 */
$baseUrl = 'http://nginx:80'; // NO SLASH!
$token = '123456';

$container = Bootstrap::boot()->createContainer();
$storage = $container->getByType(IStorage::class);
$logger = $container->getByType(ILogger::class);

// $client = new HttpApiClient($logger, $baseUrl, $token, false);
$client = new EngineBridgeApiClient($container, $token);

Bot::create($client, $storage, $logger, debug: false)
  ->run();
