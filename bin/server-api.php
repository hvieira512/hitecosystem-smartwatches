#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Server as Reactor;
use App\Http\ApiServer;
use App\Database\Database;
use App\Redis\Client as RedisClient;

$config = json_decode(
    file_get_contents(__DIR__ . '/../config/server.json'),
    true
);

$apiPort = $config['api']['port'] ?? 8081;
$apiHost = $config['api']['host'] ?? '0.0.0.0';
$wsServerUrl = getenv('WS_SERVER_URL') ?: ($config['public_ws_url'] ?? 'ws://127.0.0.1:8080');

// --- MySQL ---

$dbConfig = $config['database'] ?? null;
$db = null;
if ($dbConfig && $dbConfig['host'] !== '' && $dbConfig['name'] !== '') {
    try {
        $db = new Database($dbConfig);
        echo "[DB] Ligado a MySQL: {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['name']}\n";
    } catch (\PDOException $e) {
        echo "[DB] Aviso: sem MySQL (" . $e->getMessage() . ").\n";
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

// No WatchServer aqui — esta e uma instancia separada.
// As chamadas de comando usam Redis Stream para comunicar com o processo WS.

$apiServer = new ApiServer(
    watchServer: null,
    loop: $loop,
    port: $apiPort,
    host: $apiHost,
    db: $db,
    redis: $redis,
    wsServerUrl: $wsServerUrl,
);

echo "============================================\n";
echo "  HTTP API Server (separado)\n";
echo "  http://$apiHost:$apiPort\n";
echo "============================================\n";

$loop->run();
