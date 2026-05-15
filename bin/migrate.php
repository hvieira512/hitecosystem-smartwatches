<?php
/**
 * Migration: creates/upgrades MySQL tables and seeds initial devices.
 *
 * Usage:
 *   php bin/migrate.php              # create/upgrade tables
 *   php bin/migrate.php --seed       # create/upgrade + import whitelist.json
 *   php bin/migrate.php --seed-only  # only import whitelist.json (tables already exist)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Database\Migrator;
use App\Log\Logger;

$config = \App\Config::load()->all();
$dbConfig = $config['database'] ?? null;

if (!$dbConfig) {
    Logger::channel('db')->error("Missing 'database' configuration. Set the DB_* environment variables.");
    exit(1);
}

try {
    $pdo = \App\Database\Database::connect($dbConfig)->pdo();
} catch (\PDOException $e) {
    Logger::channel('db')->error('Failed to connect to MySQL: ' . $e->getMessage() . '. Check the credentials in .env');
    exit(1);
}

$migrator = new Migrator($pdo);
$args = $argv;
$doSeed = in_array('--seed', $args, true) || in_array('--seed-only', $args, true);
$doMigrate = !in_array('--seed-only', $args, true);

if ($doMigrate) {
    Logger::channel('db')->info('=== Creating/upgrading tables ===');
    $migrator->migrate();
}

if ($doSeed) {
    $jsonPath = __DIR__ . '/../config/whitelist.json';
    Logger::channel('db')->info("=== Importing whitelist from $jsonPath ===");
    $count = $migrator->seedFromWhitelistJson($jsonPath);
    Logger::channel('db')->info("Imported $count device(s).");
}

if (!$doMigrate && !$doSeed) {
    Logger::channel('db')->info('Nothing was done. Use --seed to migrate and import data.');
}

Logger::channel('db')->info('Done.');
