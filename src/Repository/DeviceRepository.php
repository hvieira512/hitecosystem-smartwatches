<?php

namespace App\Repository;

class DeviceRepository
{
    private \PDO $pdo;
    private const COLUMNS = 'imei, model, label, enabled, registered_at, updated_at';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM devices ORDER BY registered_at ASC');
        return $stmt->fetchAll();
    }

    public function insert(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO devices (imei, model, label, enabled, registered_at)
             VALUES (:imei, :model, :label, :enabled, :registered_at)
             ON DUPLICATE KEY UPDATE model = VALUES(model), label = VALUES(label), enabled = VALUES(enabled)'
        );
        $stmt->execute([
            'imei' => $data['imei'],
            'model' => $data['model'],
            'label' => $data['label'] ?? '',
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

    private function toMysqlDatetime(string $value): string
    {
        if ($value === 'now') {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }
}
