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
            Logger::channel('db')->error("schema.sql not found at $path");
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
        $this->addColumnIfNotExists('devices', 'client_id', 'INT UNSIGNED NULL AFTER imei');
        $this->addForeignKeyIfNotExists('devices', 'fk_devices_client',
            'FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL');
        $this->addForeignKeyIfNotExists('device_events', 'fk_events_device',
            'FOREIGN KEY (imei) REFERENCES devices(imei) ON DELETE CASCADE');

        Logger::channel('db')->info('Migration completed');
    }

    public function seedClients(): int
    {
        $repo = $this->clientRepo();
        if ($repo->count() > 0) {
            return 0;
        }

        $count = 0;
        $defaults = [
            'Default Client',
        ];

        foreach ($defaults as $name) {
            $repo->insert($name);
            $count++;
        }

        if ($count > 0) {
            Logger::channel('db')->info("Seeded $count default client(s)");
        }

        return $count;
    }

    public function seedFromWhitelistJson(string $jsonPath): int
    {
        if (!file_exists($jsonPath)) return 0;

        $devices = json_decode(file_get_contents($jsonPath), true) ?? [];
        $repo = new \App\Repository\DeviceRepository($this->pdo);
        $count = 0;

        $validClientIds = [];
        $clientStmt = $this->pdo->query('SELECT id FROM clients');
        while ($row = $clientStmt->fetch()) {
            $validClientIds[(int)$row['id']] = true;
        }

        foreach ($devices as $imei => $value) {
            $data = is_array($value) ? $value : ['model' => $value];

            $clientId = $data['client_id'] ?? null;
            if ($clientId !== null && !isset($validClientIds[(int)$clientId])) {
                $clientId = null;
            }

            $repo->insert([
                'imei' => $imei,
                'client_id' => $clientId,
                'model' => $data['model'],
                'enabled' => $data['enabled'] ?? true,
                'registered_at' => $data['registered_at'] ?? 'now',
            ]);
            $count++;
        }

        return $count;
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
            Logger::channel('db')->info("Removed column: $table.$column");
        } catch (\PDOException $e) {
            if ((string)$e->getCode() !== '42000' || !str_contains($e->getMessage(), "Can't DROP")) {
                throw $e;
            }
        }
    }

    private function addColumnIfNotExists(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            Logger::channel('db')->info("Added column: $table.$column");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not add column $table.$column: " . $e->getMessage());
        }
    }

    private function addForeignKeyIfNotExists(string $table, string $constraint, string $definition): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?'
        );
        $stmt->execute([$table, $constraint, 'FOREIGN KEY']);

        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraint` $definition");
            Logger::channel('db')->info("Added foreign key: $table.$constraint");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not add FK $constraint on $table: " . $e->getMessage());
        }
    }

    private function clientRepo(): \App\Repository\ClientRepository
    {
        return new \App\Repository\ClientRepository($this->pdo);
    }
}
