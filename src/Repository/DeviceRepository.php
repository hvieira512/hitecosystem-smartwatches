<?php

namespace App\Repository;

class DeviceRepository
{
    private \PDO $pdo;
    private const COLUMNS = 'd.imei, d.model_id, d.enabled, d.registered_at, d.updated_at, m.code AS model_code, m.name AS model_name, m.protocol, m.transport, m.enabled AS model_enabled, s.id AS supplier_id, s.name AS supplier_name';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . self::COLUMNS . '
             FROM devices d
             JOIN models m ON m.id = d.model_id
             JOIN suppliers s ON s.id = m.supplier_id
             ORDER BY d.registered_at ASC'
        );

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function find(string $imei): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM devices d
             JOIN models m ON m.id = d.model_id
             JOIN suppliers s ON s.id = m.supplier_id
             WHERE d.imei = ?'
        );
        $stmt->execute([$imei]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function insert(array $data): void
    {
        $modelId = $this->resolveModelId($data);

        $stmt = $this->pdo->prepare(
            'INSERT INTO devices (imei, model_id, enabled, registered_at)
             VALUES (:imei, :model_id, :enabled, :registered_at)
             ON DUPLICATE KEY UPDATE
                model_id = VALUES(model_id),
                enabled = VALUES(enabled),
                updated_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            'imei' => $data['imei'],
            'model_id' => $modelId,
            'enabled' => isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1,
            'registered_at' => $this->toMysqlDatetime($data['registered_at'] ?? 'now'),
        ]);
    }

    public function delete(string $imei): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM devices WHERE imei = ?');
        $stmt->execute([$imei]);
    }

    public function toggle(string $imei, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE devices SET enabled = ? WHERE imei = ?');
        $stmt->execute([$enabled ? 1 : 0, $imei]);
    }

    public function exists(string $imei): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM devices WHERE imei = ?');
        $stmt->execute([$imei]);
        return (bool)$stmt->fetchColumn();
    }

    public function ensureExists(string $imei, string $modelCode = 'unknown', bool $enabled = true): void
    {
        if (!$this->exists($imei)) {
            $this->insert([
                'imei' => $imei,
                'model' => $modelCode,
                'enabled' => $enabled,
                'registered_at' => 'now',
            ]);
        }
    }

    private function resolveModelId(array $data): int
    {
        if (isset($data['model_id'])) {
            return (int)$data['model_id'];
        }

        $modelCode = trim((string)($data['model'] ?? ''));
        if ($modelCode === '') {
            throw new \InvalidArgumentException('Device model is required');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM models WHERE code = ? LIMIT 1');
        $stmt->execute([$modelCode]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException("Model code not found: $modelCode");
        }

        return (int)$id;
    }

    private function hydrate(array $row): array
    {
        return [
            'imei' => $row['imei'],
            'model_id' => (int)$row['model_id'],
            'model_code' => $row['model_code'],
            'model_name' => $row['model_name'],
            'protocol' => $row['protocol'],
            'transport' => $row['transport'],
            'model_enabled' => (bool)$row['model_enabled'],
            'supplier_id' => (int)$row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'enabled' => (bool)$row['enabled'],
            'registered_at' => $row['registered_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function toMysqlDatetime(string $value): string
    {
        if ($value === 'now') {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }
}
