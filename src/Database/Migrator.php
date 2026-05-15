<?php

namespace App\Database;

use App\Log\Logger;
use App\Repository\DeviceRepository;

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

        $this->runSchemaSql($path);
        $this->seedModelsFromCapabilities(__DIR__ . '/../../config/capabilities.json');
        $this->migrateLegacyDeviceModelsTable();
        $this->syncDevicesModelIdFromLegacyModelColumn();
        $this->cleanupLegacySchema();
        $this->addForeignKeyIfNotExists(
            'devices',
            'fk_devices_model',
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE RESTRICT ON UPDATE RESTRICT'
        );
        $this->addForeignKeyIfNotExists(
            'device_events',
            'fk_events_device',
            'FOREIGN KEY (imei) REFERENCES devices(imei) ON DELETE CASCADE'
        );

        Logger::channel('db')->info('Migration completed');
    }

    public function seedClients(): int
    {
        Logger::channel('db')->info('Client seed is deprecated and ignored (devices are not tenant-owned anymore).');
        return 0;
    }

    public function seedFromWhitelistJson(string $jsonPath): int
    {
        if (!file_exists($jsonPath)) {
            return 0;
        }

        $devices = json_decode(file_get_contents($jsonPath), true) ?? [];
        $repo = new DeviceRepository($this->pdo);
        $count = 0;

        foreach ($devices as $imei => $value) {
            $data = is_array($value) ? $value : ['model' => $value];
            $modelCode = trim((string)($data['model'] ?? ''));
            if ($modelCode === '') {
                Logger::channel('db')->warning("Skipping IMEI $imei from whitelist: missing model");
                continue;
            }

            $this->ensureModelExistsByCode($modelCode);
            $repo->insert([
                'imei' => (string)$imei,
                'model' => $modelCode,
                'enabled' => $data['enabled'] ?? true,
                'registered_at' => $data['registered_at'] ?? 'now',
            ]);
            $count++;
        }

        return $count;
    }

    private function runSchemaSql(string $path): void
    {
        $sql = file_get_contents($path);
        $statements = explode(';', $sql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            try {
                $this->pdo->exec($statement);
            } catch (\PDOException $e) {
                Logger::channel('db')->warning($e->getMessage());
            }
        }
    }

    private function seedModelsFromCapabilities(string $jsonPath): int
    {
        if (!file_exists($jsonPath)) {
            return 0;
        }

        $profiles = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($profiles)) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO models
                (supplier_id, code, name, protocol, transport, source_doc, enabled, passive, active, features)
             VALUES
                (:supplier_id, :code, :name, :protocol, :transport, :source_doc, :enabled, :passive, :active, :features)
             ON DUPLICATE KEY UPDATE
                supplier_id = VALUES(supplier_id),
                name = VALUES(name),
                protocol = VALUES(protocol),
                transport = VALUES(transport),
                source_doc = VALUES(source_doc),
                passive = VALUES(passive),
                active = VALUES(active),
                features = VALUES(features)'
        );

        $count = 0;
        foreach ($profiles as $code => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $supplierName = trim((string)($profile['supplier'] ?? 'Unknown'));
            $supplierId = $this->upsertSupplier($supplierName === '' ? 'Unknown' : $supplierName);

            $stmt->execute([
                'supplier_id' => $supplierId,
                'code' => (string)$code,
                'name' => (string)($profile['name'] ?? $code),
                'protocol' => (string)($profile['protocol'] ?? 'unknown'),
                'transport' => (string)($profile['transport'] ?? 'unknown'),
                'source_doc' => $profile['source_doc'] ?? null,
                'enabled' => 1,
                'passive' => json_encode(array_values($profile['passive'] ?? []), JSON_UNESCAPED_UNICODE),
                'active' => json_encode(array_values($profile['active'] ?? []), JSON_UNESCAPED_UNICODE),
                'features' => json_encode($profile['features'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
            $count++;
        }

        if ($count > 0) {
            Logger::channel('db')->info("Seeded/updated $count model(s) from capabilities.json");
        }

        return $count;
    }

    private function migrateLegacyDeviceModelsTable(): void
    {
        if (!$this->tableExists('device_models')) {
            return;
        }

        $stmt = $this->pdo->query(
            'SELECT id, name, supplier, protocol, transport, source_doc, enabled, passive, active, features FROM device_models'
        );

        $upsert = $this->pdo->prepare(
            'INSERT INTO models
                (supplier_id, code, name, protocol, transport, source_doc, enabled, passive, active, features)
             VALUES
                (:supplier_id, :code, :name, :protocol, :transport, :source_doc, :enabled, :passive, :active, :features)
             ON DUPLICATE KEY UPDATE
                supplier_id = VALUES(supplier_id),
                name = VALUES(name),
                protocol = VALUES(protocol),
                transport = VALUES(transport),
                source_doc = VALUES(source_doc),
                enabled = VALUES(enabled),
                passive = VALUES(passive),
                active = VALUES(active),
                features = VALUES(features)'
        );

        $count = 0;
        while ($row = $stmt->fetch()) {
            $supplierName = trim((string)($row['supplier'] ?? 'Unknown'));
            $supplierId = $this->upsertSupplier($supplierName === '' ? 'Unknown' : $supplierName);

            $upsert->execute([
                'supplier_id' => $supplierId,
                'code' => (string)$row['id'],
                'name' => (string)($row['name'] ?? $row['id']),
                'protocol' => (string)($row['protocol'] ?? 'unknown'),
                'transport' => (string)($row['transport'] ?? 'unknown'),
                'source_doc' => $row['source_doc'] ?? null,
                'enabled' => (int)($row['enabled'] ?? 1),
                'passive' => $this->jsonColumnValue($row['passive']),
                'active' => $this->jsonColumnValue($row['active']),
                'features' => $this->jsonColumnValue($row['features'], true),
            ]);
            $count++;
        }

        if ($count > 0) {
            Logger::channel('db')->info("Migrated $count legacy row(s) from device_models to models");
        }
    }

    private function syncDevicesModelIdFromLegacyModelColumn(): void
    {
        if (!$this->tableExists('devices')) {
            return;
        }

        if (!$this->columnExists('devices', 'model_id')) {
            $this->addColumnIfNotExists('devices', 'model_id', 'INT UNSIGNED NULL AFTER imei');
        }

        if (!$this->columnExists('devices', 'model')) {
            return;
        }

        $stmt = $this->pdo->query('SELECT DISTINCT model FROM devices WHERE model IS NOT NULL AND model <> ""');
        $codes = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($codes as $code) {
            $this->ensureModelExistsByCode((string)$code);
        }

        $this->pdo->exec(
            'UPDATE devices d
             JOIN models m ON m.code = d.model
             SET d.model_id = m.id
             WHERE (d.model_id IS NULL OR d.model_id = 0)'
        );

        $remaining = (int)$this->pdo->query('SELECT COUNT(*) FROM devices WHERE model_id IS NULL OR model_id = 0')->fetchColumn();
        if ($remaining > 0) {
            $this->ensureModelExistsByCode('unknown');
            $unknownId = $this->modelIdByCode('unknown');
            if ($unknownId !== null) {
                $stmtFill = $this->pdo->prepare('UPDATE devices SET model_id = ? WHERE model_id IS NULL OR model_id = 0');
                $stmtFill->execute([$unknownId]);
            }
        }
    }

    private function cleanupLegacySchema(): void
    {
        if ($this->tableExists('devices')) {
            $this->dropForeignKeyIfExists('devices', 'fk_devices_client');
            $this->dropForeignKeyIfExists('devices', 'fk_devices_model');

            if ($this->columnExists('devices', 'client_id')) {
                $this->dropIndexIfExists('devices', 'idx_devices_client');
                $this->dropColumnIfExists('devices', 'client_id');
            }

            if ($this->columnExists('devices', 'model')) {
                $this->dropIndexIfExists('devices', 'idx_devices_model');
                $this->dropColumnIfExists('devices', 'model');
            }

            $this->dropColumnIfExists('devices', 'label');
            $this->modifyColumnIfExists('devices', 'model_id', 'INT UNSIGNED NOT NULL');
            $this->addIndexIfNotExists('devices', 'idx_devices_model_id', 'model_id');
            $this->addIndexIfNotExists('devices', 'idx_devices_enabled', 'enabled');
        }

        if ($this->tableExists('device_events')) {
            $this->dropColumnIfExists('device_events', 'model');
        }

        if ($this->tableExists('clients')) {
            try {
                $this->pdo->exec('DROP TABLE clients');
                Logger::channel('db')->info('Dropped legacy table clients');
            } catch (\PDOException $e) {
                Logger::channel('db')->warning('Could not drop legacy table clients: ' . $e->getMessage());
            }
        }

        if ($this->tableExists('device_models')) {
            try {
                $this->pdo->exec('DROP TABLE device_models');
                Logger::channel('db')->info('Dropped legacy table device_models');
            } catch (\PDOException $e) {
                Logger::channel('db')->warning('Could not drop legacy table device_models: ' . $e->getMessage());
            }
        }
    }

    private function ensureModelExistsByCode(string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }

        if ($this->modelIdByCode($code) !== null) {
            return;
        }

        $supplierId = $this->upsertSupplier('Unknown');
        $stmt = $this->pdo->prepare(
            'INSERT INTO models
                (supplier_id, code, name, protocol, transport, source_doc, enabled, passive, active, features)
             VALUES
                (:supplier_id, :code, :name, :protocol, :transport, NULL, 0, :passive, :active, :features)'
        );
        $stmt->execute([
            'supplier_id' => $supplierId,
            'code' => $code,
            'name' => $code,
            'protocol' => 'unknown',
            'transport' => 'unknown',
            'passive' => json_encode([], JSON_UNESCAPED_UNICODE),
            'active' => json_encode([], JSON_UNESCAPED_UNICODE),
            'features' => json_encode([], JSON_UNESCAPED_UNICODE),
        ]);

        Logger::channel('db')->warning("Created stub disabled model for existing devices: $code");
    }

    private function upsertSupplier(string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppliers (name, enabled)
             VALUES (:name, 1)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), enabled = VALUES(enabled)'
        );
        $stmt->execute(['name' => mb_substr($name, 0, 50)]);
        return (int)$this->pdo->lastInsertId();
    }

    private function modelIdByCode(string $code): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM models WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function jsonColumnValue(mixed $value, bool $object = false): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return json_encode($object ? [] : [], JSON_UNESCAPED_UNICODE);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function addColumnIfNotExists(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            Logger::channel('db')->info("Added column: $table.$column");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not add column $table.$column: " . $e->getMessage());
        }
    }

    private function modifyColumnIfExists(string $table, string $column, string $definition): void
    {
        if (!$this->columnExists($table, $column)) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not modify column $table.$column: " . $e->getMessage());
        }
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (!$this->columnExists($table, $column)) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
            Logger::channel('db')->info("Removed column: $table.$column");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not drop column $table.$column: " . $e->getMessage());
        }
    }

    private function addForeignKeyIfNotExists(string $table, string $constraint, string $definition): void
    {
        if ($this->foreignKeyExists($table, $constraint)) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraint` $definition");
            Logger::channel('db')->info("Added foreign key: $table.$constraint");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not add FK $constraint on $table: " . $e->getMessage());
        }
    }

    private function dropForeignKeyIfExists(string $table, string $constraint): void
    {
        if (!$this->foreignKeyExists($table, $constraint)) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$constraint`");
            Logger::channel('db')->info("Dropped foreign key: $table.$constraint");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not drop FK $constraint on $table: " . $e->getMessage());
        }
    }

    private function foreignKeyExists(string $table, string $constraint): bool
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
        return (int)$stmt->fetchColumn() > 0;
    }

    private function addIndexIfNotExists(string $table, string $indexName, string $column): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` ADD INDEX `$indexName` (`$column`)");
            Logger::channel('db')->info("Added index: $table.$indexName");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not add index $indexName on $table: " . $e->getMessage());
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExists($table, $indexName)) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE `$table` DROP INDEX `$indexName`");
            Logger::channel('db')->info("Dropped index: $table.$indexName");
        } catch (\PDOException $e) {
            Logger::channel('db')->warning("Could not drop index $indexName on $table: " . $e->getMessage());
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $indexName]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
