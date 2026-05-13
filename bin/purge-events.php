#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;

$config = json_decode(
    file_get_contents(__DIR__ . '/../config/server.json'),
    true
);
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
    echo "[Purge] ERRO: configuracao MySQL necessaria.\n";
    exit(1);
}

try {
    $db = new Database($dbConfig);
} catch (\PDOException $e) {
    echo "[Purge] ERRO: MySQL indisponivel (" . $e->getMessage() . ").\n";
    exit(1);
}

$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$olderThan} days"));
echo "[Purge] Eventos anteriores a {$cutoffDate}, max {$keepPerDevice} por dispositivo\n";

if ($dryRun) {
    echo "[Purge] Modo dry-run — sem alteracoes\n";
    echo "[Purge] Eventos removiveis por dispositivo:\n";

    $imeis = $db->deviceAll();
    $totalRemovable = 0;
    foreach ($imeis as $device) {
        $imei = $device['imei'];
        $count = $db->eventCount($imei);

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

$purged = $db->eventPurgeOlderThan($cutoffDate, $keepPerDevice);
echo "[Purge] Removidos {$purged} eventos.\n";
