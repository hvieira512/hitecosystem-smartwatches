#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;
use App\Repository\DeviceRepository;
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

Logger::channel('worker')->info('Starting (PID: ' . getmypid() . ')');

// --- MySQL ---
if (!$dbConfig || $dbConfig['host'] === '' || $dbConfig['name'] === '') {
    Logger::channel('worker')->error('MySQL configuration is required');
    exit(1);
}
try {
    $db = Database::connect($dbConfig);
    $pdo = $db->pdo();
    $eventsRepo = new EventRepository($pdo);
    $devicesRepo = new DeviceRepository($pdo);
    Logger::channel('worker')->info("Connected to MySQL at {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['name']}");
} catch (\PDOException $e) {
    Logger::channel('worker')->error('MySQL unavailable (' . $e->getMessage() . '). Shutting down');
    exit(1);
}

// --- Redis ---
$redisHost = getenv('REDIS_HOST') ?: ($redisConfig['host'] ?? '');
if ($redisHost === '') {
    Logger::channel('worker')->error('Redis configuration is required');
    exit(1);
}
$redis = null;
try {
    $redis = new RedisClient($redisConfig);
    if (!$redis->isAvailable()) {
        throw new \RuntimeException("RedisClient not available");
    }
    Logger::channel('worker')->info("Connected to Redis at $redisHost");
} catch (\Throwable $e) {
    Logger::channel('worker')->error('Redis unavailable (' . $e->getMessage() . '). Shutting down');
    exit(1);
}

// --- Consumer Group ---
$redis->xGroupCreate($groupName, $streamKey, '0', true);
Logger::channel('worker')->info("Group '{$groupName}' ready on stream '{$streamKey}'");

// --- Signal Handling ---
if (extension_loaded('pcntl')) {
    pcntl_signal(SIGINT, function () use (&$running) {
        Logger::channel('worker')->info('SIGINT received. Shutting down gracefully...');
        $running = false;
    });
    pcntl_signal(SIGTERM, function () use (&$running) {
        Logger::channel('worker')->info('SIGTERM received. Shutting down gracefully...');
        $running = false;
    });
}

// --- Main Loop ---
Logger::channel('worker')->info("Consuming events from '{$streamKey}' (consumer: {$consumerName})");

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
            $devicesRepo->ensureExists($event['imei']);
            $eventsRepo->insert($event);
            $ackIds[] = $event['streamId'];
            $totalProcessed++;

            if ($totalProcessed % 100 === 0) {
                Logger::channel('worker')->info("{$totalProcessed} events processed");
            }
        } catch (\Throwable $e) {
            Logger::channel('worker')->error("Failed to insert event {$event['streamId']}: {$e->getMessage()}");
            $ackIds[] = $event['streamId'];
        }
    }

    if (!empty($ackIds)) {
        try {
            $ackd = $redis->xAck($streamKey, $groupName, $ackIds);
            if ($ackd !== count($ackIds)) {
                Logger::channel('worker')->warning("Acknowledged {$ackd}/" . count($ackIds) . " events");
            }
        } catch (\Throwable $e) {
            Logger::channel('worker')->error("xAck: {$e->getMessage()}");
        }
    }
}

Logger::channel('worker')->info("Stopped. Total processed: {$totalProcessed} events");
