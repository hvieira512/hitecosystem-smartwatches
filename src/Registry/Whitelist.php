<?php

namespace App\Registry;

use App\Repository\DeviceRepository;
use App\Database\Migrator;
use App\Log\Logger;

class Whitelist
{
    private array $devices;
    private string $filePath;
    private ?DeviceRepository $deviceRepo;
    private ?Migrator $migrator;

    public function __construct(?string $filePath = null, ?\PDO $pdo = null)
    {
        $this->filePath = $filePath ?? __DIR__ . '/../../config/whitelist.json';
        $this->deviceRepo = $pdo ? new DeviceRepository($pdo) : null;
        $this->migrator = $pdo ? new Migrator($pdo) : null;
        $this->load();
    }

    private function load(): void
    {
        if ($this->deviceRepo) {
            $this->loadFromDatabase();
            return;
        }
        $this->loadFromFile();
    }

    private function loadFromDatabase(): void
    {
        $rows = $this->deviceRepo->all();
        $this->devices = [];

        foreach ($rows as $row) {
            $this->devices[$row['imei']] = [
                'model' => $row['model_code'],
                'enabled' => (bool)$row['enabled'],
                'registered_at' => $row['registered_at'],
            ];
        }

        if (empty($this->devices) && file_exists($this->filePath)) {
            $count = $this->migrator?->seedFromWhitelistJson($this->filePath) ?? 0;
            if ($count > 0) {
                Logger::channel('whitelist')->info("Migrated $count devices from $this->filePath to MySQL");
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

        $raw = json_decode(file_get_contents($this->filePath), true) ?? [];
        $this->devices = [];
        foreach ($raw as $imei => $value) {
            if (is_array($value)) {
                $this->devices[$imei] = [
                    'model' => $value['model'] ?? '',
                    'enabled' => $value['enabled'] ?? true,
                    'registered_at' => $value['registered_at'] ?? null,
                ];
            } else {
                $this->devices[$imei] = [
                    'model' => (string)$value,
                    'enabled' => true,
                    'registered_at' => null,
                ];
            }
        }
    }

    public function isAuthorized(string $imei): bool
    {
        return isset($this->devices[$imei]) && $this->devices[$imei]['enabled'] === true;
    }

    public function getModel(string $imei): ?string
    {
        return $this->devices[$imei]['model'] ?? null;
    }

    public function all(): array
    {
        return $this->devices;
    }

    public function register(string $imei, string $model, bool $enabled = true): void
    {
        $data = [
            'imei' => $imei,
            'model' => $model,
            'enabled' => $enabled,
            'registered_at' => date('c'),
        ];

        $this->devices[$imei] = [
            'model' => $model,
            'enabled' => $enabled,
            'registered_at' => date('c'),
        ];

        if ($this->deviceRepo) {
            $this->deviceRepo->insert($data);
        } else {
            $this->saveFile();
        }
    }

    public function unregister(string $imei): void
    {
        unset($this->devices[$imei]);

        if ($this->deviceRepo) {
            $this->deviceRepo->delete($imei);
        } else {
            $this->saveFile();
        }
    }

    public function toggle(string $imei, bool $enabled): void
    {
        if (!isset($this->devices[$imei])) {
            return;
        }

        $this->devices[$imei]['enabled'] = $enabled;
        if ($this->deviceRepo) {
            $this->deviceRepo->toggle($imei, $enabled);
        } else {
            $this->saveFile();
        }
    }

    public function update(string $imei, array $data): bool
    {
        if (!isset($this->devices[$imei])) {
            return false;
        }

        if (isset($data['model'])) {
            $this->devices[$imei]['model'] = $data['model'];
        }
        if (isset($data['enabled'])) {
            $this->devices[$imei]['enabled'] = (bool)$data['enabled'];
        }

        if ($this->deviceRepo) {
            $repoData = ['imei' => $imei];
            if (isset($data['model'])) {
                $repoData['model'] = $data['model'];
            }
            if (isset($data['enabled'])) {
                $repoData['enabled'] = $data['enabled'];
            }

            if (count($repoData) > 1) {
                $existing = $this->deviceRepo->find($imei);
                $current = $existing
                    ? [
                        'imei' => $existing['imei'],
                        'model' => $existing['model_code'],
                        'enabled' => $existing['enabled'],
                        'registered_at' => $existing['registered_at'],
                    ]
                    : ['imei' => $imei, 'model' => $this->devices[$imei]['model'] ?? '', 'enabled' => true];
                $merged = array_merge($current, $repoData);
                $this->deviceRepo->insert($merged);
            }
        } else {
            $this->saveFile();
        }

        return true;
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
