<?php

namespace App\Protocol\Adapter;

interface DeviceAdapterInterface
{
    public function protocol(): string;

    public function canDecode(string $raw): bool;

    public function decodeIncoming(string $raw, array $context = []): ?array;

    public function encodeOutgoing(array $payload, array $context = []): string;
}
