<?php

namespace App\Repository;

class ClientRepository
{
    private \PDO $pdo;
    private const COLUMNS = 'id, name, created_at, updated_at';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM clients ORDER BY name ASC');
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insert(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO clients (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE clients SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM clients WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function count(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    }
}
