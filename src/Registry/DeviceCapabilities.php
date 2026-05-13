<?php

namespace App\Registry;

class DeviceCapabilities
{
    private static ?array $profiles = null;
    private static ?string $profilesPath = null;

    public static function setProfilesPath(string $path): void
    {
        self::$profiles = null;
        self::$profilesPath = $path;
    }

    private static function load(): void
    {
        if (self::$profiles !== null) {
            return;
        }

        $path = self::$profilesPath ?? __DIR__ . '/../../config/capabilities.json';

        if (!file_exists($path)) {
            self::$profiles = [];
            return;
        }

        self::$profiles = json_decode(file_get_contents($path), true) ?? [];
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

    public static function modelLabel(string $model): string
    {
        self::load();
        return self::$profiles[$model]['label'] ?? $model;
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

    // --- Instancia ---

    private string $model;
    private string $label;
    private ?string $supplier;
    private ?string $protocol;
    private ?string $transport;
    private ?string $sourceDoc;
    private array $passive;
    private array $active;
    private array $features;

    private function __construct(string $model, array $profile)
    {
        $this->model = $model;
        $this->label = $profile['label'] ?? $model;
        $this->supplier = $profile['supplier'] ?? null;
        $this->protocol = $profile['protocol'] ?? null;
        $this->transport = $profile['transport'] ?? null;
        $this->sourceDoc = $profile['source_doc'] ?? null;
        $this->passive = $profile['passive'] ?? [];
        $this->active = $profile['active'] ?? [];
        $this->features = $profile['features'] ?? [];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getLabel(): string
    {
        return $this->label;
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
            'passive' => $this->passive,
            'active'  => $this->active,
            'features' => $this->features,
        ];
    }
}
