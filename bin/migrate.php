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

$config = \App\Config::load()->all();
$dbConfig = $config['database'] ?? null;

if (!$dbConfig) {
    echo "[ERRO] Sem configuracao 'database'. Defina variaveis de ambiente DB_*\n";
    exit(1);
}

try {
    $pdo = \App\Database\Database::connect($dbConfig)->pdo();
} catch (\PDOException $e) {
    echo "[ERRO] Falha ao ligar ao MySQL: " . $e->getMessage() . "\n";
    echo "       Verifique as credenciais em .env\n";
    exit(1);
}

$migrator = new Migrator($pdo);

$args = $argv;
$doSeed = in_array('--seed', $args) || in_array('--seed-only', $args);
$doMigrate = !in_array('--seed-only', $args);

if ($doMigrate) {
    echo "=== A criar tabelas ===\n";
    $migrator->migrate();
}

if ($doSeed) {
    $jsonPath = __DIR__ . '/../config/whitelist.json';
    echo "=== A importar whitelist de $jsonPath ===\n";
    $count = $migrator->seedFromWhitelistJson($jsonPath);
    echo "Importados $count dispositivos.\n";
}

if (!$doMigrate && !$doSeed) {
    echo "Nada foi feito. Use --seed para criar tabelas e importar.\n";
}

echo "Concluido.\n";
