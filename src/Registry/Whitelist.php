<?php

namespace App\Registry;

use App\Database\Database;

class Whitelist
{
    private array $devices;
    private string $filePath;
    private ?Database $db;

    public function __construct(?string $filePath = null, ?Database $db = null)
    {
        $this->filePath = $filePath ?? __DIR__ . '/../../config/whitelist.json';
        $this->db = $db;
        $this->load();
    }

    private function load(): void
    {
        if ($this->db) {
            $this->loadFromDatabase();
            return;
        }
        $this->loadFromFile();
    }

    private function loadFromDatabase(): void
    {
        $rows = $this->db->deviceAll();
        $this->devices = [];

        foreach ($rows as $row) {
            $this->devices[$row['imei']] = [
                'model' => $row['model'],
                'label' => $row['label'],
                'enabled' => (bool)$row['enabled'],
                'registered_at' => $row['registered_at'],
            ];
        }

        if (empty($this->devices) && file_exists($this->filePath)) {
            $count = $this->db->seedFromWhitelistJson($this->filePath);
            if ($count > 0) {
                echo "[Whitelist] Migrados $count dispositivos de $this->filePath para MySQL\n";
                $this->loadFromDatabase();
            }
        }
    }

    private function loadFromFile(): void
    {
        if (!file_exists($this->filePath)) {
            $this->devices = [];
            return;
        }
        $this->devices = json_decode(file_get_contents($this->filePath), true) ?? [];
    }

    public function isAuthorized(string $imei): bool
    {
        return isset($this->devices[$imei]) && $this->devices[$imei]['enabled'] === true;
    }

    public function getModel(string $imei): ?string
    {
        return $this->devices[$imei]['model'] ?? null;
    }

    public function getLabel(string $imei): ?string
    {
        return $this->devices[$imei]['label'] ?? null;
    }

    public function all(): array
    {
        return $this->devices;
    }

    public function register(string $imei, string $model, string $label = ''): void
    {
        $data = [
            'imei' => $imei,
            'model' => $model,
            'label' => $label ?: "Device $imei",
            'enabled' => true,
            'registered_at' => date('c'),
        ];
        $this->devices[$imei] = $data;

        if ($this->db) {
            $this->db->deviceInsert($data);
        } else {
            $this->saveFile();
        }
    }

    public function unregister(string $imei): void
    {
        unset($this->devices[$imei]);

        if ($this->db) {
            $this->db->deviceDelete($imei);
        } else {
            $this->saveFile();
        }
    }

    public function toggle(string $imei, bool $enabled): void
    {
        if (isset($this->devices[$imei])) {
            $this->devices[$imei]['enabled'] = $enabled;

            if ($this->db) {
                $this->db->deviceToggle($imei, $enabled);
            } else {
                $this->saveFile();
            }
        }
    }

    private function saveFile(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->filePath,
            json_encode($this->devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
