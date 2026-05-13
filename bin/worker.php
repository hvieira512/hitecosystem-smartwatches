#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;
use App\Redis\Client as RedisClient;

$config = \App\Config::load()->all();
$dbConfig = $config['database'] ?? null;
$redisConfig = $config['redis'] ?? [];

$workerId = (gethostname() ?: 'worker') . ':' . getmypid();
$streamKey = 'events';
$groupName = 'stream:worker';
$consumerName = $workerId;
$totalProcessed = 0;
$running = true;

echo "[Worker] Iniciando (PID: " . getmypid() . ")\n";

// --- MySQL ---
if (!$dbConfig || $dbConfig['host'] === '' || $dbConfig['name'] === '') {
    echo "[Worker] ERRO: configuracao MySQL necessaria.\n";
    exit(1);
}
try {
    $db = new Database($dbConfig);
    echo "[Worker] MySQL ligado a {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['name']}\n";
} catch (\PDOException $e) {
    echo "[Worker] ERRO: MySQL indisponivel (" . $e->getMessage() . "). A encerrar.\n";
    exit(1);
}

// --- Redis ---
$redisHost = getenv('REDIS_HOST') ?: ($redisConfig['host'] ?? '');
if ($redisHost === '') {
    echo "[Worker] ERRO: configuracao Redis necessaria.\n";
    exit(1);
}
$redis = null;
try {
    $redis = new RedisClient($redisConfig);
    if (!$redis->isAvailable()) {
        throw new \RuntimeException("RedisClient not available");
    }
    echo "[Worker] Redis ligado a $redisHost\n";
} catch (\Throwable $e) {
    echo "[Worker] ERRO: Redis indisponivel (" . $e->getMessage() . "). A encerrar.\n";
    exit(1);
}

// --- Consumer Group ---
$redis->xGroupCreate($groupName, $streamKey, '0', true);
echo "[Worker] Grupo '{$groupName}' pronto no stream '{$streamKey}'\n";

// --- Signal Handling ---
if (extension_loaded('pcntl')) {
    pcntl_signal(SIGINT, function () use (&$running) {
        echo "\n[Worker] SIGINT recebido. A encerrar graciosamente...\n";
        $running = false;
    });
    pcntl_signal(SIGTERM, function () use (&$running) {
        echo "\n[Worker] SIGTERM recebido. A encerrar graciosamente...\n";
        $running = false;
    });
}

// --- Main Loop ---
echo "[Worker] A consumir eventos de '{$streamKey}' (consumidor: {$consumerName})\n";

while ($running) {
    if (extension_loaded('pcntl')) {
        pcntl_signal_dispatch();
    }

    try {
        $messages = $redis->xReadGroup($groupName, $consumerName, 50, 2000);
    } catch (\Throwable $e) {
        echo "[Worker] Erro xReadGroup: {$e->getMessage()}\n";
        sleep(1);
        continue;
    }

    if (empty($messages)) {
        continue;
    }

    $ackIds = [];
    foreach ($messages as $event) {
        try {
            $db->eventInsert($event);
            $ackIds[] = $event['streamId'];
            $totalProcessed++;

            if ($totalProcessed % 100 === 0) {
                echo "[Worker] {$totalProcessed} eventos processados\n";
            }
        } catch (\Throwable $e) {
            echo "[Worker] Erro ao inserir evento {$event['streamId']}: {$e->getMessage()}\n";
            $ackIds[] = $event['streamId'];
        }
    }

    if (!empty($ackIds)) {
        try {
            $ackd = $redis->xAck($streamKey, $groupName, $ackIds);
            if ($ackd !== count($ackIds)) {
                echo "[Worker] Aviso: acertados {$ackd}/" . count($ackIds) . " eventos\n";
            }
        } catch (\Throwable $e) {
            echo "[Worker] Erro xAck: {$e->getMessage()}\n";
        }
    }
}

echo "[Worker] Encerrado. Total processado: {$totalProcessed} eventos.\n";
