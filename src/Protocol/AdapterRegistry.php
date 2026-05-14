<?php

namespace App\Protocol;

use App\Protocol\Adapter\DeviceAdapterInterface;
use App\Protocol\Adapter\VivistarAdapter;
use App\Protocol\Adapter\WonlexAdapter;
use App\Registry\DeviceCapabilities;

class AdapterRegistry
{
    /** @var array<string, DeviceAdapterInterface> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [];
        $this->register(new WonlexAdapter());
        $this->register(new VivistarAdapter());
    }

    public function register(DeviceAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->protocol()] = $adapter;
    }

    public function resolveForModel(string $model): ?DeviceAdapterInterface
    {
        $caps = DeviceCapabilities::forModel($model);
        if (!$caps) {
            return null;
        }

        $protocol = $caps->getProtocol();
        return $protocol ? ($this->adapters[$protocol] ?? null) : null;
    }

    public function detectFromMessage(string $raw): ?DeviceAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->canDecode($raw)) {
                return $adapter;
            }
        }

        return null;
    }

    public function decodeAny(string $raw, array $context = []): ?array
    {
        $adapter = $this->detectFromMessage($raw);
        if ($adapter === null) {
            return null;
        }

        $payload = $adapter->decodeIncoming($raw, $context);
        if ($payload === null) {
            return null;
        }

        $payload['_protocol'] = $adapter->protocol();
        return $payload;
    }

    public function get(string $protocol): ?DeviceAdapterInterface
    {
        return $this->adapters[$protocol] ?? null;
    }
}
