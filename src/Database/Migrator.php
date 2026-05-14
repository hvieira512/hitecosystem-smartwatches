<?php

namespace App\Database;

use App\Log\Logger;

class Migrator
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function migrate(): void
    {
        $path = __DIR__ . '/../../config/schema.sql';
        if (!file_exists($path)) {
            Logger::channel('db')->error("schema.sql nao encontrado em $path");
            return;
        }

        $sql = file_get_contents($path);
        $statements = explode(';', $sql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException $e) {
                    Logger::channel('db')->warning($e->getMessage());
                }
            }
        }

        $this->dropColumnIfExists('devices', 'label');
        $this->dropColumnIfExists('device_events', 'model');

        Logger::channel('db')->info('Migracao concluida');
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        if ((int)$stmt->fetchColumn() === 0) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
            Logger::channel('db')->info("Coluna removida: $table.$column");
        } catch (\PDOException $e) {
            if ((string)$e->getCode() !== '42000' || !str_contains($e->getMessage(), "Can't DROP")) {
                throw $e;
            }
        }
    }

    public function seedFromWhitelistJson(string $jsonPath): int
    {
        if (!file_exists($jsonPath)) return 0;

        $devices = json_decode(file_get_contents($jsonPath), true) ?? [];
        $repo = new \App\Repository\DeviceRepository($this->pdo);
        $count = 0;

        foreach ($devices as $imei => $value) {
            $data = is_array($value) ? $value : ['model' => $value];
            $repo->insert([
                'imei' => $imei,
                'model' => $data['model'],
                'enabled' => $data['enabled'] ?? true,
                'registered_at' => $data['registered_at'] ?? 'now',
            ]);
            $count++;
        }

        return $count;
    }
}
