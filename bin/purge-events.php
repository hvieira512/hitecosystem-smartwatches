#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Log\Logger;

$config = \App\Config::load()->all();
$dbConfig = $config['database'] ?? null;

$help = <<<HELP
Usage: php bin/purge-events.php [options]

Options:
  --older-than DAYS   Delete events older than DAYS days (default: 30)
  --keep-per-device N Keep at most N events per device (default: 1000)
  --dry-run           Show what would be deleted without deleting
  --help              Show this help

HELP;

$args = array_slice($argv, 1);
$dryRun = false;
$olderThan = 30;
$keepPerDevice = 1000;

foreach ($args as $arg) {
    if ($arg === '--help') {
        echo $help;
        exit(0);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--older-than=(\d+)$/', $arg, $m)) {
        $olderThan = (int)$m[1];
        continue;
    }
    if (preg_match('/^--keep-per-device=(\d+)$/', $arg, $m)) {
        $keepPerDevice = (int)$m[1];
        continue;
    }
    Logger::channel('purge')->warning("Unknown option: $arg");
    echo $help;
    exit(1);
}

if (!$dbConfig || $dbConfig['host'] === '' || $dbConfig['name'] === '') {
    Logger::channel('purge')->error('MySQL configuration is required');
    exit(1);
}

try {
    $db = Database::connect($dbConfig);
    $devicesRepo = new DeviceRepository($db->pdo());
    $eventsRepo = new EventRepository($db->pdo());
} catch (\PDOException $e) {
    Logger::channel('purge')->error('MySQL unavailable (' . $e->getMessage() . ')');
    exit(1);
}

$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$olderThan} days"));
Logger::channel('purge')->info("Events before {$cutoffDate}, max {$keepPerDevice} per device");

if ($dryRun) {
    Logger::channel('purge')->info('Dry-run mode - no changes will be made');
    Logger::channel('purge')->info('Removable events by device:');

    $imeis = $devicesRepo->all();
    $totalRemovable = 0;
    foreach ($imeis as $device) {
        $imei = $device['imei'];
        $count = $eventsRepo->count($imei);

        if ($count > $keepPerDevice) {
            $removable = $count - $keepPerDevice;
            Logger::channel('purge')->info("  $imei: $count events, $removable removable");
            $totalRemovable += $removable;
        }
    }

    Logger::channel('purge')->info("Total removable: ~{$totalRemovable} events");
    exit(0);
}

$purged = $eventsRepo->purgeOlderThan($cutoffDate, $keepPerDevice);
Logger::channel('purge')->info("Removed {$purged} events");
