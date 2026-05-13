<?php

namespace App\Repository;

class EventRepository
{
    private \PDO $pdo;
    private const COLUMNS = 'id, imei, model, native_type, feature, native_payload, received_at, created_at';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(array $event): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_events (imei, model, native_type, feature, native_payload, received_at)
             VALUES (:imei, :model, :native_type, :feature, :native_payload, :received_at)'
        );
        $stmt->execute([
            'imei' => $event['imei'],
            'model' => $event['model'],
            'native_type' => $event['nativeType'],
            'feature' => $event['feature'],
            'native_payload' => json_encode($event['nativePayload']),
            'received_at' => $event['receivedAt'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findRecent(int $limit = 50, ?int $afterId = null, ?string $imei = null): array
    {
        $where = [];
        $params = [];

        if ($afterId !== null) {
            $where[] = 'id > :after_id';
            $params['after_id'] = $afterId;
        }

        if ($imei !== null) {
            $where[] = 'imei = :imei';
            $params['imei'] = $imei;
        }

        $sql = 'SELECT ' . self::COLUMNS . ' FROM device_events';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY received_at DESC LIMIT :limit';
        $params['limit'] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'imei' => $row['imei'],
            'model' => $row['model'],
            'nativeType' => $row['native_type'],
            'feature' => $row['feature'],
            'nativePayload' => json_decode($row['native_payload'], true) ?? [],
            'receivedAt' => (int)$row['received_at'],
        ], $stmt->fetchAll());
    }

    public function latestForImei(string $imei): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM device_events WHERE imei = ? ORDER BY received_at DESC LIMIT 1'
        );
        $stmt->execute([$imei]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return [
            'id' => (int)$row['id'],
            'imei' => $row['imei'],
            'model' => $row['model'],
            'nativeType' => $row['native_type'],
            'feature' => $row['feature'],
            'nativePayload' => json_decode($row['native_payload'], true) ?? [],
            'receivedAt' => (int)$row['received_at'],
        ];
    }

    public function count(?string $imei = null): int
    {
        if ($imei !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM device_events WHERE imei = ?');
            $stmt->execute([$imei]);
        } else {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM device_events');
        }
        return (int)$stmt->fetchColumn();
    }

    public function latestForAllImeis(): array
    {
        $stmt = $this->pdo->query(
            'SELECT e.' . self::COLUMNS . ' FROM device_events e
             INNER JOIN (
                 SELECT imei, MAX(received_at) AS max_ts
                 FROM device_events GROUP BY imei
             ) latest ON e.imei = latest.imei AND e.received_at = latest.max_ts'
        );
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['imei']] = [
                'id' => (int)$row['id'],
                'imei' => $row['imei'],
                'model' => $row['model'],
                'nativeType' => $row['native_type'],
                'feature' => $row['feature'],
                'nativePayload' => json_decode($row['native_payload'], true) ?? [],
                'receivedAt' => (int)$row['received_at'],
            ];
        }
        return $result;
    }

    public function purgeOlderThan(string $date, int $keepPerDevice = 1000): int
    {
        $purged = 0;

        $imeis = $this->pdo->query('SELECT DISTINCT imei FROM device_events')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($imeis as $imei) {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM device_events WHERE imei = ?');
            $count->execute([$imei]);
            $total = (int)$count->fetchColumn();

            if ($total > $keepPerDevice) {
                $deleteCount = $total - $keepPerDevice;
                $stmt = $this->pdo->prepare(
                    'DELETE FROM device_events WHERE imei = ? ORDER BY received_at ASC LIMIT ?'
                );
                $stmt->execute([$imei, $deleteCount]);
                $purged += $stmt->rowCount();
            }
        }

        $stmt = $this->pdo->prepare('DELETE FROM device_events WHERE created_at < ?');
        $stmt->execute([$date]);
        $purged += $stmt->rowCount();

        return $purged;
    }
}
