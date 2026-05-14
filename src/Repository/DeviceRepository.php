<?php

namespace App\Repository;

class DeviceRepository
{
    private \PDO $pdo;
    private const COLUMNS = 'd.imei, d.client_id, d.model, d.enabled, d.registered_at, d.updated_at';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(?int $clientId = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ', c.name AS client_name FROM devices d LEFT JOIN clients c ON c.id = d.client_id';
        $params = [];

        if ($clientId !== null) {
            $sql .= ' WHERE d.client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        $sql .= ' ORDER BY d.registered_at ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $row): array => [
            'imei' => $row['imei'],
            'client_id' => $row['client_id'] ? (int)$row['client_id'] : null,
            'client_name' => $row['client_name'],
            'model' => $row['model'],
            'enabled' => (bool)$row['enabled'],
            'registered_at' => $row['registered_at'],
            'updated_at' => $row['updated_at'],
        ], $stmt->fetchAll());
    }

    public function insert(array $data): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO devices (imei, client_id, model, enabled, registered_at)
                 VALUES (:imei, :client_id, :model, :enabled, :registered_at)
                 ON DUPLICATE KEY UPDATE client_id = IFNULL(VALUES(client_id), client_id), model = VALUES(model), enabled = VALUES(enabled)'
            );
            $stmt->execute([
                'imei' => $data['imei'],
                'client_id' => isset($data['client_id']) ? (int)$data['client_id'] : null,
                'model' => $data['model'],
                'enabled' => isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1,
                'registered_at' => $this->toMysqlDatetime($data['registered_at'] ?? 'now'),
            ]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000' && $data['client_id'] !== null) {
                $data['client_id'] = null;
                $this->insert($data);
                return;
            }
            throw $e;
        }
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

    public function assignClient(string $imei, ?int $clientId): void
    {
        $stmt = $this->pdo->prepare('UPDATE devices SET client_id = ? WHERE imei = ?');
        $stmt->execute([$clientId, $imei]);
    }

    public function exists(string $imei): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM devices WHERE imei = ?');
        $stmt->execute([$imei]);
        return (bool)$stmt->fetchColumn();
    }

    public function ensureExists(string $imei, string $model = 'unknown', bool $enabled = true): void
    {
        if (!$this->exists($imei)) {
            $this->insert([
                'imei' => $imei,
                'model' => $model,
                'enabled' => $enabled,
                'registered_at' => 'now',
            ]);
        }
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
