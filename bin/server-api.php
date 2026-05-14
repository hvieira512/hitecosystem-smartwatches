#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Server as Reactor;
use App\Http\ApiServer;
use App\Database\Database;
use App\Log\Logger;
use App\Redis\Client as RedisClient;

$config = \App\Config::load()->all();

$apiPort = $config['api']['port'] ?? 8081;
$apiHost = $config['api']['host'] ?? '0.0.0.0';
$wsServerUrl = getenv('WS_SERVER_URL') ?: ($config['public_ws_url'] ?? 'ws://127.0.0.1:8080');

// --- MySQL ---

$dbConfig = $config['database'] ?? null;
$db = null;
if ($dbConfig && $dbConfig['host'] !== '' && $dbConfig['name'] !== '') {
    try {
        $db = Database::connect($dbConfig);
        Logger::channel('db')->info("Connected to MySQL at {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['name']}");
    } catch (\PDOException $e) {
        Logger::channel('db')->warning('MySQL unavailable (' . $e->getMessage() . ')');
    }
}

// --- Redis ---

$redisConfig = $config['redis'] ?? [];
$redisHost = getenv('REDIS_HOST') ?: ($redisConfig['host'] ?? '');
$redis = null;
if ($redisHost !== '') {
    $redis = new RedisClient($redisConfig);
}

// --- Event Loop ---

$loop = Loop::get();

// No WatchServer here - this is a separate instance.
// Command calls use Redis Stream to communicate with the WS process.

$apiServer = new ApiServer(
    watchServer: null,
    loop: $loop,
    port: $apiPort,
    host: $apiHost,
    pdo: $db?->pdo(),
    redis: $redis,
    wsServerUrl: $wsServerUrl,
);

Logger::channel('app')->info("=== HTTP API Server (separate) ===");
Logger::channel('app')->info("http://$apiHost:$apiPort");

$loop->run();
