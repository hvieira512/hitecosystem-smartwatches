<?php
/**
 * Migracao: cria tabelas MySQL e faz seed da whitelist.
 *
 * Uso:
 *   php bin/migrate.php                        # criar tabelas
 *   php bin/migrate.php --seed                  # criar tabelas + importar whitelist.json
 *   php bin/migrate.php --seed-only             # apenas importar whitelist (tabelas ja existem)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Database\Migrator;
use App\Log\Logger;

$config = \App\Config::load()->all();
$dbConfig = $config['database'] ?? null;

if (!$dbConfig) {
    Logger::channel('db')->error("Sem configuracao 'database'. Defina variaveis de ambiente DB_*");
    exit(1);
}

try {
    $pdo = \App\Database\Database::connect($dbConfig)->pdo();
} catch (\PDOException $e) {
    Logger::channel('db')->error('Falha ao ligar ao MySQL: ' . $e->getMessage() . '. Verifique as credenciais em .env');
    exit(1);
}

$migrator = new Migrator($pdo);

$args = $argv;
$doSeed = in_array('--seed', $args) || in_array('--seed-only', $args);
$doMigrate = !in_array('--seed-only', $args);

if ($doMigrate) {
    Logger::channel('db')->info('=== A criar tabelas ===');
    $migrator->migrate();
}

if ($doSeed) {
    $jsonPath = __DIR__ . '/../config/whitelist.json';
    Logger::channel('db')->info("=== A importar whitelist de $jsonPath ===");
    $count = $migrator->seedFromWhitelistJson($jsonPath);
    Logger::channel('db')->info("Importados $count dispositivos.");
}

if (!$doMigrate && !$doSeed) {
    Logger::channel('db')->info('Nada foi feito. Use --seed para criar tabelas e importar.');
}

Logger::channel('db')->info('Concluido.');
