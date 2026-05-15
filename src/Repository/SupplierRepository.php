<?php

namespace App\Repository;

class SupplierRepository
{
    private \PDO $pdo;
    private const COLUMNS = 'id, name, enabled, created_at, updated_at';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM suppliers WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function list(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = ($page - 1) * $limit;

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM suppliers'
            . $where
            . ' ORDER BY name ASC LIMIT :limit OFFSET :offset'
        );

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
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM suppliers' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppliers (name, enabled)
             VALUES (:name, :enabled)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'enabled' => $data['enabled'] ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $sets = [];
        $params = ['id' => $id];

        foreach ($data as $key => $value) {
            $sets[] = "$key = :$key";
            $params[$key] = $key === 'enabled' ? ($value ? 1 : 0) : $value;
        }

        $stmt = $this->pdo->prepare('UPDATE suppliers SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countModelsUsingSupplier(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM models WHERE supplier_id = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['name'] ?? null) !== null && $filters['name'] !== '') {
            $where[] = 'name LIKE :name';
            $params['name'] = '%' . $filters['name'] . '%';
        }

        if (($filters['enabled'] ?? null) !== null) {
            $where[] = 'enabled = :enabled';
            $params['enabled'] = $filters['enabled'] ? 1 : 0;
        }

        return [$where ? (' WHERE ' . implode(' AND ', $where)) : '', $params];
    }

    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'enabled' => (bool)$row['enabled'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
