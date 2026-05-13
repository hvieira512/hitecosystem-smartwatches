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
    echo "Unknown option: $arg\n";
    echo $help;
    exit(1);
}

if (!$dbConfig || $dbConfig['host'] === '' || $dbConfig['name'] === '') {
    Logger::channel('purge')->error('configuracao MySQL necessaria');
    exit(1);
}

try {
    $db = Database::connect($dbConfig);
    $devicesRepo = new DeviceRepository($db->pdo());
    $eventsRepo = new EventRepository($db->pdo());
} catch (\PDOException $e) {
    Logger::channel('purge')->error('MySQL indisponivel (' . $e->getMessage() . ')');
    exit(1);
}

$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$olderThan} days"));
Logger::channel('purge')->info("Eventos anteriores a {$cutoffDate}, max {$keepPerDevice} por dispositivo");

if ($dryRun) {
    Logger::channel('purge')->info('Modo dry-run — sem alteracoes');
    Logger::channel('purge')->info('Eventos removiveis por dispositivo:');

    $imeis = $devicesRepo->all();
    $totalRemovable = 0;
    foreach ($imeis as $device) {
        $imei = $device['imei'];
        $count = $eventsRepo->count($imei);

        if ($count > $keepPerDevice) {
            $removable = $count - $keepPerDevice;
            echo "  $imei: $count eventos, $removable removiveis\n";
            $totalRemovable += $removable;
        }
    }

    $oldCount = 'N/A (purged during per-device cleanup)';
    echo "\nTotal removivel: ~{$totalRemovable} eventos\n";
    exit(0);
}

$purged = $eventsRepo->purgeOlderThan($cutoffDate, $keepPerDevice);
Logger::channel('purge')->info("Removidos {$purged} eventos");
