<?php

namespace App\Database;

class Database
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function connect(array $config): self
    {
        $host = getenv('DB_HOST') ?: ($config['host'] ?? '127.0.0.1');
        $port = getenv('DB_PORT') ?: ($config['port'] ?? 3306);
        $name = getenv('DB_NAME') ?: ($config['name'] ?? 'health_watches');
        $user = getenv('DB_USER') ?: ($config['user'] ?? 'root');
        $pass = getenv('DB_PASS') ?: ($config['pass'] ?? '');

        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return new self($pdo);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }
}
