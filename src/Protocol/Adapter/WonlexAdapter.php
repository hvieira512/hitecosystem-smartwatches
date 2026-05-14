<?php

namespace App\Protocol\Adapter;

class WonlexAdapter implements DeviceAdapterInterface
{
    public function protocol(): string
    {
        return 'wonlex-json';
    }

    public function canDecode(string $raw): bool
    {
        if (strlen($raw) < 4) {
            return false;
        }

        $header = @unpack('nstart/nlength', substr($raw, 0, 4));
        return ($header['start'] ?? null) === 0xFCAF;
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        if (!$this->canDecode($raw)) {
            return null;
        }

        $header = unpack('nstart/nlength', substr($raw, 0, 4));
        $length = $header['length'] ?? 0;
        $json = substr($raw, 4, $length);
        $payload = json_decode($json, true);

        if (!is_array($payload) || !isset($payload['type'])) {
            return null;
        }

        return $payload;
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }

        return pack('nn', 0xFCAF, strlen($json)) . $json;
    }
}
