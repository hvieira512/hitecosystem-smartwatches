<?php

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Server as Reactor;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\WatchServer;
use App\Http\ApiServer;
use App\Database\Database;
use App\Log\Logger;
use App\Redis\Client as RedisClient;

$config = \App\Config::load()->all();

$wsPort = $config['websocket']['port'] ?? 8080;
$wsHost = $config['websocket']['host'] ?? '0.0.0.0';
$apiPort = $config['api']['port'] ?? 8081;
$apiHost = $config['api']['host'] ?? '0.0.0.0';
$caps = json_decode(file_get_contents(__DIR__ . '/config/capabilities.json'), true) ?? [];

// --- MySQL ---

$dbConfig = $config['database'] ?? null;
$db = null;
if ($dbConfig && $dbConfig['host'] !== '' && $dbConfig['name'] !== '') {
    try {
        $db = Database::connect($dbConfig);
        Logger::channel('db')->info("Connected to MySQL at {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['name']}");
    } catch (\PDOException $e) {
        Logger::channel('db')->warning('MySQL unavailable (' . $e->getMessage() . '). Using JSON files');
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

$watchServer = new WatchServer($db?->pdo(), $redis);

$wsApp = new HttpServer(
    new WsServer($watchServer)
);
$wsSocket = new Reactor("$wsHost:$wsPort", $loop);
$wsServer = new IoServer($wsApp, $wsSocket, $loop);

$apiServer = new ApiServer($watchServer, $loop, $apiPort, $apiHost);

// Note: Redis Stream -> MySQL event persistence is handled by the dedicated worker
// (bin/worker.php) usando consumer groups XREADGROUP.

Logger::channel('app')->info("=== Multi-Vendor 4G Smartwatch Server ===");
Logger::channel('app')->info("WebSocket: ws://$wsHost:$wsPort");
Logger::channel('app')->info("HTTP API:  http://$apiHost:$apiPort");
Logger::channel('app')->info("Models:    " . implode(', ', array_keys($caps)));

$loop->run();
