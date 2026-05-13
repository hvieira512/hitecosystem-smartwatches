<?php

namespace App\Database;

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

        $devices = json_decode(file_get_contents($jsonPath), true) ?? [];
        $repo = new \App\Repository\DeviceRepository($this->pdo);
        $count = 0;

        foreach ($devices as $imei => $value) {
            $data = is_array($value) ? $value : ['model' => $value];
            $repo->insert([
                'imei' => $imei,
                'model' => $data['model'],
                'label' => $data['label'] ?? '',
                'enabled' => $data['enabled'] ?? true,
                'registered_at' => $data['registered_at'] ?? 'now',
            ]);
            $count++;
        }

        return $count;
    }
}
