<?php
/**
 * Migration: creates MySQL tables and seeds the whitelist.
 *
 * Uso:
 *   php bin/migrate.php                        # create tables
 *   php bin/migrate.php --seed                  # create tables + import whitelist.json
 *   php bin/migrate.php --seed-only             # only import whitelist (tables already exist)
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
$doSeed = in_array('--seed', $args) || in_array('--seed-only', $args);
$doMigrate = !in_array('--seed-only', $args);

if ($doMigrate) {
    Logger::channel('db')->info('=== Creating tables ===');
    $migrator->migrate();
}

if ($doSeed) {
    $jsonPath = __DIR__ . '/../config/whitelist.json';
    Logger::channel('db')->info("=== Importing whitelist from $jsonPath ===");
    $count = $migrator->seedFromWhitelistJson($jsonPath);
    Logger::channel('db')->info("Imported $count devices.");
}

if (!$doMigrate && !$doSeed) {
    Logger::channel('db')->info('Nothing was done. Use --seed to create tables and import data.');
}

Logger::channel('db')->info('Done.');
