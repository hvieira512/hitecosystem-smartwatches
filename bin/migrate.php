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

$config = json_decode(file_get_contents(__DIR__ . '/../config/server.json'), true);
$dbConfig = $config['database'] ?? null;

if (!$dbConfig) {
    echo "[ERRO] Sem configuracao 'database' em config/server.json\n";
    exit(1);
}

try {
    $db = new App\Database\Database($dbConfig);
} catch (\PDOException $e) {
    echo "[ERRO] Falha ao ligar ao MySQL: " . $e->getMessage() . "\n";
    echo "       Verifique as credenciais em config/server.json\n";
    exit(1);
}

$args = $argv;
$doSeed = in_array('--seed', $args) || in_array('--seed-only', $args);
$doMigrate = !in_array('--seed-only', $args);

if ($doMigrate) {
    echo "=== A criar tabelas ===\n";
    $db->migrate();
}

if ($doSeed) {
    $jsonPath = __DIR__ . '/../config/whitelist.json';
    echo "=== A importar whitelist de $jsonPath ===\n";
    $count = $db->seedFromWhitelistJson($jsonPath);
    echo "Importados $count dispositivos.\n";
}

if (!$doMigrate && !$doSeed) {
    echo "Nada foi feito. Use --seed para criar tabelas e importar.\n";
}

echo "Concluido.\n";
