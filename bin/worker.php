#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;
use App\Repository\EventRepository;
use App\Log\Logger;
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

Logger::channel('worker')->info('Iniciando (PID: ' . getmypid() . ')');

// --- MySQL ---
if (!$dbConfig || $dbConfig['host'] === '' || $dbConfig['name'] === '') {
    Logger::channel('worker')->error('configuracao MySQL necessaria');
    exit(1);
}
try {
    $db = Database::connect($dbConfig);
    $eventsRepo = new EventRepository($db->pdo());
    Logger::channel('worker')->info("MySQL ligado a {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['name']}");
} catch (\PDOException $e) {
    Logger::channel('worker')->error('MySQL indisponivel (' . $e->getMessage() . '). A encerrar');
    exit(1);
}

// --- Redis ---
$redisHost = getenv('REDIS_HOST') ?: ($redisConfig['host'] ?? '');
if ($redisHost === '') {
    Logger::channel('worker')->error('configuracao Redis necessaria');
    exit(1);
}
$redis = null;
try {
    $redis = new RedisClient($redisConfig);
    if (!$redis->isAvailable()) {
        throw new \RuntimeException("RedisClient not available");
    }
    Logger::channel('worker')->info("Redis ligado a $redisHost");
} catch (\Throwable $e) {
    Logger::channel('worker')->error('Redis indisponivel (' . $e->getMessage() . '). A encerrar');
    exit(1);
}

// --- Consumer Group ---
$redis->xGroupCreate($groupName, $streamKey, '0', true);
Logger::channel('worker')->info("Grupo '{$groupName}' pronto no stream '{$streamKey}'");

// --- Signal Handling ---
if (extension_loaded('pcntl')) {
    pcntl_signal(SIGINT, function () use (&$running) {
        Logger::channel('worker')->info('SIGINT recebido. A encerrar graciosamente...');
        $running = false;
    });
    pcntl_signal(SIGTERM, function () use (&$running) {
        Logger::channel('worker')->info('SIGTERM recebido. A encerrar graciosamente...');
        $running = false;
    });
}

// --- Main Loop ---
Logger::channel('worker')->info("A consumir eventos de '{$streamKey}' (consumidor: {$consumerName})");

while ($running) {
    if (extension_loaded('pcntl')) {
        pcntl_signal_dispatch();
    }

    try {
        $messages = $redis->xReadGroup($groupName, $consumerName, 50, 2000);
    } catch (\Throwable $e) {
        Logger::channel('worker')->error("xReadGroup: {$e->getMessage()}");
        sleep(1);
        continue;
    }

    if (empty($messages)) {
        continue;
    }

    $ackIds = [];
    foreach ($messages as $event) {
        try {
            $eventsRepo->insert($event);
            $ackIds[] = $event['streamId'];
            $totalProcessed++;

            if ($totalProcessed % 100 === 0) {
                Logger::channel('worker')->info("{$totalProcessed} eventos processados");
            }
        } catch (\Throwable $e) {
            Logger::channel('worker')->error("Erro ao inserir evento {$event['streamId']}: {$e->getMessage()}");
            $ackIds[] = $event['streamId'];
        }
    }

    if (!empty($ackIds)) {
        try {
            $ackd = $redis->xAck($streamKey, $groupName, $ackIds);
            if ($ackd !== count($ackIds)) {
                Logger::channel('worker')->warning("acertados {$ackd}/" . count($ackIds) . " eventos");
            }
        } catch (\Throwable $e) {
            Logger::channel('worker')->error("xAck: {$e->getMessage()}");
        }
    }
}

Logger::channel('worker')->info("Encerrado. Total processado: {$totalProcessed} eventos");
