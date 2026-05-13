#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Server as Reactor;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\WatchServer;
use App\Database\Database;
use App\Log\Logger;
use App\Redis\Client as RedisClient;

$config = \App\Config::load()->all();

$wsPort = $config['websocket']['port'] ?? 8080;
$wsHost = $config['websocket']['host'] ?? '0.0.0.0';

// --- MySQL ---

$dbConfig = $config['database'] ?? null;
$db = null;
if ($dbConfig && $dbConfig['host'] !== '' && $dbConfig['name'] !== '') {
    try {
        $db = Database::connect($dbConfig);
        Logger::channel('db')->info("Ligado a MySQL: {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['name']}");
    } catch (\PDOException $e) {
        Logger::channel('db')->warning('sem MySQL (' . $e->getMessage() . '). A usar ficheiros JSON');
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

// --- Redis Command Stream Consumer ---
// Recebe comandos do processo API via Redis Stream e envia para os dispositivos

if ($redis !== null && $redis->isAvailable()) {
    $redis->xGroupCreate('cmd:worker', 'cmd:stream', '0', true);
    Logger::channel('ws-cmd')->info("Grupo 'cmd:worker' pronto no stream 'cmd:stream'");

    $loop->addPeriodicTimer(0.5, function () use ($redis, $watchServer) {
        try {
            $commands = $redis->commandReadGroup('cmd:worker', 'ws:' . gethostname(), 10, 100);
            if (empty($commands)) return;

            $ackIds = [];
            foreach ($commands as $cmd) {
                $imei = $cmd['imei'];
                $type = $cmd['type'];
                $data = $cmd['data'];

                if ($cmd['feature'] !== '') {
                    $sent = $watchServer->sendFeatureCommand($imei, $cmd['feature'], $data);
                } else {
                    $sent = $watchServer->sendCommand($imei, $type, $data);
                }

                Logger::channel('ws-cmd')->info("IMEI=$imei type=$type " . ($sent ? 'enviado' : 'falhou'));
                $ackIds[] = $cmd['streamId'];
            }

            if (!empty($ackIds)) {
                $redis->xAck('cmd:stream', 'cmd:worker', $ackIds);
            }
        } catch (\Throwable $e) {
            Logger::channel('ws-cmd')->error("Erro: {$e->getMessage()}");
        }
    });
    Logger::channel('ws-cmd')->info('Ativo: Redis Stream -> WebSocket commands');
}

Logger::channel('app')->info("=== WebSocket Server (separado) ===");
Logger::channel('app')->info("ws://$wsHost:$wsPort");

$loop->run();
