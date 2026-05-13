<?php

namespace App\Database;

class Database
{
    private \PDO $pdo;

    public function __construct(array $config)
    {
        $host = getenv('DB_HOST') ?: ($config['host'] ?? '127.0.0.1');
        $port = getenv('DB_PORT') ?: ($config['port'] ?? 3306);
        $name = getenv('DB_NAME') ?: ($config['name'] ?? 'health_watches');
        $user = getenv('DB_USER') ?: ($config['user'] ?? 'root');
        $pass = getenv('DB_PASS') ?: ($config['pass'] ?? '');

        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    // --- Whitelist / Devices ---

    public function deviceAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM devices ORDER BY registered_at ASC');
        return $stmt->fetchAll();
    }

    public function deviceFindByImei(string $imei): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM devices WHERE imei = ?');
        $stmt->execute([$imei]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function deviceInsert(array $data): void
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

    public function deviceUpdate(string $imei, array $data): void
    {
        $fields = [];
        $params = ['imei' => $imei];

        foreach (['model', 'label'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        if (isset($data['enabled'])) {
            $fields[] = 'enabled = :enabled';
            $params['enabled'] = $data['enabled'] ? 1 : 0;
        }


        if (empty($fields)) return;

        $sql = 'UPDATE devices SET ' . implode(', ', $fields) . ' WHERE imei = :imei';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function deviceDelete(string $imei): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM devices WHERE imei = ?');
        $stmt->execute([$imei]);
    }

    public function deviceToggle(string $imei, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE devices SET enabled = ? WHERE imei = ?');
        $stmt->execute([$enabled ? 1 : 0, $imei]);
    }

    // --- Events ---

    public function eventInsert(array $event): int
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

    public function eventFindRecent(int $limit = 50, ?int $afterId = null, ?string $imei = null): array
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

        $sql = 'SELECT * FROM device_events';
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

    public function eventLatestForImei(string $imei): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM device_events WHERE imei = ? ORDER BY received_at DESC LIMIT 1'
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

    public function eventCount(?string $imei = null): int
    {
        if ($imei !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM device_events WHERE imei = ?');
            $stmt->execute([$imei]);
        } else {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM device_events');
        }
        return (int)$stmt->fetchColumn();
    }

    public function eventLatestForAllImeis(): array
    {
        $stmt = $this->pdo->query(
            'SELECT e.* FROM device_events e
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

    public function eventPurgeOlderThan(string $date, int $keepPerDevice = 1000): int
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

    // --- Migration ---

    public function migrate(): void
    {
        $path = __DIR__ . '/../../config/schema.sql';
        if (!file_exists($path)) {
            echo "[DB] schema.sql nao encontrado em $path\n";
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
                    echo "[DB] Aviso: " . $e->getMessage() . "\n";
                }
            }
        }

        echo "[DB] Migracao concluida.\n";
    }

    public function seedFromWhitelistJson(string $jsonPath): int
    {
        if (!file_exists($jsonPath)) return 0;

        $jsonDevices = json_decode(file_get_contents($jsonPath), true) ?? [];
        $count = 0;
        foreach ($jsonDevices as $imei => $info) {
            $this->deviceInsert([
                'imei' => $imei,
                'model' => $info['model'],
                'label' => $info['label'] ?? '',
                'enabled' => $info['enabled'] ?? true,
                'registered_at' => $info['registered_at'] ?? 'now',
            ]);
            $count++;
        }
        return $count;
    }

    // --- Helpers ---

    private function toMysqlDatetime(string $value): string
    {
        if ($value === 'now') {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }
}
