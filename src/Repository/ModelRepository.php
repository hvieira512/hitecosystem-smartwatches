<?php

namespace App\Repository;

class ModelRepository
{
    private \PDO $pdo;
    private const COLUMNS = 'm.id, m.supplier_id, s.name AS supplier_name, m.code, m.name, m.protocol, m.transport, m.source_doc, m.enabled, m.passive, m.active, m.features, m.created_at, m.updated_at';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM models m
             JOIN suppliers s ON s.id = m.supplier_id
             WHERE m.code = ?'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM models m
             JOIN suppliers s ON s.id = m.supplier_id
             WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function existsCode(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM models WHERE code = ?');
        $stmt->execute([$code]);
        return (bool)$stmt->fetchColumn();
    }

    public function isEnabledByCode(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT enabled FROM models WHERE code = ?');
        $stmt->execute([$code]);
        $value = $stmt->fetchColumn();
        return $value !== false && (bool)$value;
    }

    public function list(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = ($page - 1) * $limit;
        $sql = 'SELECT ' . self::COLUMNS . '
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id'
            . $where
            . ' ORDER BY m.code ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM models m
             JOIN suppliers s ON s.id = m.supplier_id'
            . $where
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO models
                (supplier_id, code, name, protocol, transport, source_doc, enabled, passive, active, features)
             VALUES
                (:supplier_id, :code, :name, :protocol, :transport, :source_doc, :enabled, :passive, :active, :features)'
        );

        $stmt->execute($this->serialize($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function updateByCode(string $code, array $data): void
    {
        if ($data === []) {
            return;
        }

        $sets = [];
        $params = ['code_filter' => $code];
        foreach ($data as $key => $value) {
            $sets[] = "m.$key = :$key";
            $params[$key] = $value;
        }

        $stmt = $this->pdo->prepare('UPDATE models m SET ' . implode(', ', $sets) . ' WHERE m.code = :code_filter');
        $stmt->execute($this->serialize($params));
    }

    public function deleteByCode(string $code): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM models WHERE code = ?');
        $stmt->execute([$code]);
    }

    public function countDevicesUsingModelCode(string $code): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM devices d
             JOIN models m ON m.id = d.model_id
             WHERE m.code = ?'
        );
        $stmt->execute([$code]);
        return (int)$stmt->fetchColumn();
    }

    public function allProfiles(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . self::COLUMNS . '
             FROM models m
             JOIN suppliers s ON s.id = m.supplier_id'
        );

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['code'] ?? null) !== null && $filters['code'] !== '') {
            $where[] = 'm.code LIKE :code';
            $params['code'] = '%' . $filters['code'] . '%';
        }
        if (($filters['name'] ?? null) !== null && $filters['name'] !== '') {
            $where[] = 'm.name LIKE :name';
            $params['name'] = '%' . $filters['name'] . '%';
        }
        if (($filters['supplierId'] ?? null) !== null) {
            $where[] = 'm.supplier_id = :supplier_id';
            $params['supplier_id'] = (int)$filters['supplierId'];
        }
        if (($filters['supplierName'] ?? null) !== null && $filters['supplierName'] !== '') {
            $where[] = 's.name = :supplier_name';
            $params['supplier_name'] = $filters['supplierName'];
        }
        if (($filters['protocol'] ?? null) !== null && $filters['protocol'] !== '') {
            $where[] = 'm.protocol = :protocol';
            $params['protocol'] = $filters['protocol'];
        }
        if (($filters['transport'] ?? null) !== null && $filters['transport'] !== '') {
            $where[] = 'm.transport = :transport';
            $params['transport'] = $filters['transport'];
        }
        if (($filters['enabled'] ?? null) !== null) {
            $where[] = 'm.enabled = :enabled';
            $params['enabled'] = $filters['enabled'] ? 1 : 0;
        }

        return [$where ? (' WHERE ' . implode(' AND ', $where)) : '', $params];
    }

    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'supplier_id' => (int)$row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'code' => $row['code'],
            'name' => $row['name'],
            'protocol' => $row['protocol'],
            'transport' => $row['transport'],
            'source_doc' => $row['source_doc'],
            'enabled' => (bool)$row['enabled'],
            'passive' => $this->decodeJsonArray($row['passive']),
            'active' => $this->decodeJsonArray($row['active']),
            'features' => $this->decodeJsonObject($row['features']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function serialize(array $data): array
    {
        $serialized = $data;

        if (array_key_exists('enabled', $serialized)) {
            $serialized['enabled'] = $serialized['enabled'] ? 1 : 0;
        }
        if (array_key_exists('passive', $serialized) && is_array($serialized['passive'])) {
            $serialized['passive'] = json_encode(array_values($serialized['passive']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('active', $serialized) && is_array($serialized['active'])) {
            $serialized['active'] = json_encode(array_values($serialized['active']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('features', $serialized) && is_array($serialized['features'])) {
            $serialized['features'] = json_encode($serialized['features'], JSON_UNESCAPED_UNICODE);
        }

        return $serialized;
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
