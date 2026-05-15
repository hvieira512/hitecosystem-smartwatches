<?php

namespace App\Registry;

use App\Log\Logger;
use App\Repository\ModelRepository;

class DeviceCapabilities
{
    private static ?array $profiles = null;
    private static ?string $profilesPath = null;
    private static ?\PDO $pdo = null;
    private static int $cacheTtlSeconds = 5;
    private static int $lastLoadedAt = 0;

    public static function setProfilesPath(string $path): void
    {
        self::$profiles = null;
        self::$lastLoadedAt = 0;
        self::$profilesPath = $path;
    }

    public static function setDatabasePdo(?\PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$profiles = null;
        self::$lastLoadedAt = 0;
    }

    public static function setCacheTtl(int $seconds): void
    {
        self::$cacheTtlSeconds = max(1, $seconds);
    }

    private static function load(): void
    {
        $now = time();
        if (self::$profiles !== null && ($now - self::$lastLoadedAt) < self::$cacheTtlSeconds) {
            return;
        }

        $profiles = self::loadFromDatabase();
        if ($profiles === null || $profiles === []) {
            if ($profiles === []) {
                Logger::channel('capabilities')->warning('Model catalog in MySQL is empty; falling back to capabilities.json');
            }
            $profiles = self::loadFromJson();
        }

        self::$profiles = $profiles;
        self::$lastLoadedAt = $now;
    }

    private static function loadFromDatabase(): ?array
    {
        if (self::$pdo === null) {
            return null;
        }

        try {
            $repo = new ModelRepository(self::$pdo);
            $rows = $repo->allProfiles();
            $profiles = [];
            foreach ($rows as $row) {
                $profiles[$row['code']] = [
                    'name' => $row['name'],
                    'supplier' => $row['supplier_name'],
                    'protocol' => $row['protocol'],
                    'transport' => $row['transport'],
                    'source_doc' => $row['source_doc'],
                    'enabled' => (bool)$row['enabled'],
                    'passive' => $row['passive'],
                    'active' => $row['active'],
                    'features' => $row['features'],
                ];
            }
            return $profiles;
        } catch (\Throwable $e) {
            Logger::channel('capabilities')->warning('Failed to load capabilities from MySQL: ' . $e->getMessage());
            return null;
        }
    }

    private static function loadFromJson(): array
    {
        $path = self::$profilesPath ?? __DIR__ . '/../../config/capabilities.json';

        if (!file_exists($path)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public static function forModel(string $model): ?self
    {
        self::load();
        if (!isset(self::$profiles[$model])) {
            return null;
        }
        return new self($model, self::$profiles[$model]);
    }

    public static function allModels(): array
    {
        self::load();
        return array_keys(self::$profiles);
    }

    public static function modelName(string $model): string
    {
        self::load();
        return self::$profiles[$model]['name'] ?? $model;
    }

    public static function supportsAny(string $command): bool
    {
        self::load();
        foreach (self::$profiles as $profile) {
            if (in_array($command, $profile['passive'] ?? [], true)) {
                return true;
            }
            if (in_array($command, $profile['active'] ?? [], true)) {
                return true;
            }
        }
        return false;
    }

    public static function allKnownPassive(): array
    {
        self::load();
        $all = [];
        foreach (self::$profiles as $profile) {
            $all = array_merge($all, $profile['passive'] ?? []);
        }
        return array_values(array_unique($all));
    }

    public static function allKnownActive(): array
    {
        self::load();
        $all = [];
        foreach (self::$profiles as $profile) {
            $all = array_merge($all, $profile['active'] ?? []);
        }
        return array_values(array_unique($all));
    }

    // --- Instance ---

    private string $model;
    private string $name;
    private ?string $supplier;
    private ?string $protocol;
    private ?string $transport;
    private ?string $sourceDoc;
    private bool $enabled;
    private array $passive;
    private array $active;
    private array $features;

    private function __construct(string $model, array $profile)
    {
        $this->model = $model;
        $this->name = $profile['name'] ?? $model;
        $this->supplier = $profile['supplier'] ?? null;
        $this->protocol = $profile['protocol'] ?? null;
        $this->transport = $profile['transport'] ?? null;
        $this->sourceDoc = $profile['source_doc'] ?? null;
        $this->enabled = (bool)($profile['enabled'] ?? true);
        $this->passive = $profile['passive'] ?? [];
        $this->active = $profile['active'] ?? [];
        $this->features = $profile['features'] ?? [];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSupplier(): ?string
    {
        return $this->supplier;
    }

    public function getProtocol(): ?string
    {
        return $this->protocol;
    }

    public function getTransport(): ?string
    {
        return $this->transport;
    }

    public function getSourceDoc(): ?string
    {
        return $this->sourceDoc;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function supportsPassive(string $type): bool
    {
        return in_array($type, $this->passive, true);
    }

    public function supportsActive(string $type): bool
    {
        return in_array($type, $this->active, true);
    }

    public function supportsFeature(string $feature): bool
    {
        return isset($this->features[$feature]);
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function getFeature(string $feature): ?array
    {
        return $this->features[$feature] ?? null;
    }

    public function getFeatureNames(): array
    {
        return array_keys($this->features);
    }

    public function featureForPassive(string $type): ?string
    {
        foreach ($this->features as $feature => $commands) {
            if (in_array($type, $commands['passive'] ?? [], true)) {
                return $feature;
            }
        }

        return null;
    }

    public function resolveFeatureActiveCommand(string $feature): ?string
    {
        $commands = $this->features[$feature]['active'] ?? [];
        foreach ($commands as $command) {
            if ($this->supportsActive($command)) {
                return $command;
            }
        }

        return null;
    }

    public function getPassive(): array
    {
        return $this->passive;
    }

    public function getActive(): array
    {
        return $this->active;
    }

    public function toArray(): array
    {
        return [
            'supplier' => $this->supplier,
            'protocol' => $this->protocol,
            'transport' => $this->transport,
            'source_doc' => $this->sourceDoc,
            'enabled' => $this->enabled,
            'passive' => $this->passive,
            'active'  => $this->active,
            'features' => $this->features,
        ];
    }
}
