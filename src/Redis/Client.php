<?php

namespace App\Redis;

class Client
{
    private ?\Redis $redis = null;
    private bool $available = false;
    private string $nodeId;

    public function __construct(array $config)
    {
        $host = getenv('REDIS_HOST') ?: ($config['host'] ?? '127.0.0.1');
        $port = (int)(getenv('REDIS_PORT') ?: ($config['port'] ?? 6379));
        $password = $config['password'] ?? null;
        $dbIndex = (int)($config['database'] ?? 0);

        $this->nodeId = gethostname() ?: 'node-' . bin2hex(random_bytes(4));

        if (!extension_loaded('redis')) {
            echo "[Redis] extensao 'redis' nao disponivel. Funcionalidades Redis desativadas.\n";
            return;
        }

        try {
            $this->redis = new \Redis();
            $this->redis->connect($host, $port, 2.0);
            if ($password) {
                $this->redis->auth($password);
            }
            if ($dbIndex > 0) {
                $this->redis->select($dbIndex);
            }
            $this->redis->ping();
            $this->available = true;
            echo "[Redis] Ligado a $host:$port (node: {$this->nodeId})\n";
        } catch (\Throwable $e) {
            $this->redis = null;
            $this->available = false;
            echo "[Redis] Aviso: sem ligacao Redis (" . $e->getMessage() . ").\n";
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    // --- Device Registry ---

    public function deviceSetOnline(string $imei): void
    {
        if (!$this->available) return;
        try {
            $this->redis->hSet('device:online', $imei, $this->nodeId);
        } catch (\Throwable $e) {
            echo "[Redis] Erro deviceSetOnline: {$e->getMessage()}\n";
        }
    }

    public function deviceSetOffline(string $imei): void
    {
        if (!$this->available) return;
        try {
            $this->redis->hDel('device:online', $imei);
        } catch (\Throwable $e) {
            echo "[Redis] Erro deviceSetOffline: {$e->getMessage()}\n";
        }
    }

    public function deviceGetNode(string $imei): ?string
    {
        if (!$this->available) return null;
        try {
            $node = $this->redis->hGet('device:online', $imei);
            return $node === false ? null : $node;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getAllOnlineDevices(): array
    {
        if (!$this->available) return [];
        try {
            $result = $this->redis->hGetAll('device:online');
            return $result ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // --- Events Stream ---

    public function eventPush(array $event): string
    {
        if (!$this->available) return '0-0';
        try {
            return $this->redis->xAdd(
                'events',
                '*',
                [
                    'imei' => $event['imei'],
                    'model' => $event['model'],
                    'native_type' => $event['nativeType'],
                    'feature' => $event['feature'] ?? '',
                    'native_payload' => json_encode($event['nativePayload']),
                    'received_at' => $event['receivedAt'],
                ],
                10000
            );
        } catch (\Throwable $e) {
            echo "[Redis] Erro eventPush: {$e->getMessage()}\n";
            return '0-0';
        }
    }

    public function readEvents(string $lastId, int $count = 50): array
    {
        if (!$this->available) return [];
        try {
            $streams = $this->redis->xRead(['events' => $lastId], $count);
            if (!$streams) return [];

            $events = [];
            foreach ($streams as $streamName => $streamEvents) {
                foreach ($streamEvents as $id => $data) {
                    $events[] = [
                        'streamId' => $id,
                        'imei' => $data['imei'],
                        'model' => $data['model'],
                        'nativeType' => $data['native_type'],
                        'feature' => $data['feature'] ?: null,
                        'nativePayload' => json_decode($data['native_payload'], true) ?? [],
                        'receivedAt' => (int)$data['received_at'],
                    ];
                }
            }
            return $events;
        } catch (\Throwable $e) {
            echo "[Redis] Erro readEvents: {$e->getMessage()}\n";
            return [];
        }
    }

    public function getStreamLength(): int
    {
        if (!$this->available) return 0;
        try {
            return (int)$this->redis->xLen('events');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function trimStream(int $maxLen = 10000): void
    {
        if (!$this->available) return;
        try {
            $this->redis->xTrim('events', $maxLen);
        } catch (\Throwable $e) {
            // silent
        }
    }

    // --- Consumer Groups (worker) ---

    public function xGroupCreate(string $group, string $stream, string $id = '0', bool $mkStream = true): bool
    {
        if (!$this->available) return false;
        try {
            $this->redis->xGroup('CREATE', $stream, $group, $id, $mkStream);
            return true;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'BUSYGROUP')) {
                return true;
            }
            echo "[Redis] Erro xGroupCreate: {$e->getMessage()}\n";
            return false;
        }
    }

    public function xReadGroup(string $group, string $consumer, int $count = 10, int $blockMs = 2000): array
    {
        if (!$this->available) return [];
        try {
            $result = $this->redis->xReadGroup($group, $consumer, ['events' => '>'], $count, $blockMs);
            if (!$result) return [];

            $events = [];
            foreach ($result as $streamName => $entries) {
                foreach ($entries as $id => $data) {
                    $events[] = [
                        'streamId' => $id,
                        'stream' => $streamName,
                        'imei' => $data['imei'],
                        'model' => $data['model'],
                        'nativeType' => $data['native_type'],
                        'feature' => $data['feature'] ?? '',
                        'nativePayload' => json_decode($data['native_payload'], true) ?? [],
                        'receivedAt' => (int)$data['received_at'],
                    ];
                }
            }
            return $events;
        } catch (\Throwable $e) {
            echo "[Redis] Erro xReadGroup: {$e->getMessage()}\n";
            return [];
        }
    }

    public function xAck(string $stream, string $group, array $ids): int
    {
        if (!$this->available) return 0;
        try {
            return $this->redis->xAck($stream, $group, $ids);
        } catch (\Throwable $e) {
            echo "[Redis] Erro xAck: {$e->getMessage()}\n";
            return 0;
        }
    }

    // --- Command Stream (API -> WS) ---

    public function commandPublish(array $command): string
    {
        if (!$this->available) return '';
        try {
            return $this->redis->xAdd('cmd:stream', '*', [
                'imei' => $command['imei'],
                'type' => $command['type'],
                'payload' => json_encode($command['data'] ?? []),
                'request_id' => $command['requestId'] ?? '',
                'feature' => $command['feature'] ?? '',
                'source' => $command['source'] ?? 'api',
                'timestamp' => (string)(int)round(microtime(true) * 1000),
            ], 5000);
        } catch (\Throwable $e) {
            echo "[Redis] Erro commandPublish: {$e->getMessage()}\n";
            return '';
        }
    }

    public function commandReadGroup(string $group, string $consumer, int $count = 10, int $blockMs = 2000): array
    {
        if (!$this->available) return [];
        try {
            $result = $this->redis->xReadGroup($group, $consumer, ['cmd:stream' => '>'], $count, $blockMs);
            if (!$result) return [];

            $commands = [];
            foreach ($result as $streamName => $entries) {
                foreach ($entries as $id => $data) {
                    $commands[] = [
                        'streamId' => $id,
                        'stream' => $streamName,
                        'imei' => $data['imei'],
                        'type' => $data['type'],
                        'data' => json_decode($data['payload'], true) ?? [],
                        'requestId' => $data['request_id'] ?? '',
                        'feature' => $data['feature'] ?? '',
                        'source' => $data['source'] ?? '',
                    ];
                }
            }
            return $commands;
        } catch (\Throwable $e) {
            echo "[Redis] Erro commandReadGroup: {$e->getMessage()}\n";
            return [];
        }
    }

    // --- Rate Limiting ---

    public function rateLimitCheck(string $key, int $maxPerMinute = 30): bool
    {
        if (!$this->available) return true;
        try {
            $redisKey = "ratelimit:$key:" . time();
            $count = $this->redis->incr($redisKey);
            if ($count === 1) {
                $this->redis->expire($redisKey, 62);
            }
            return $count <= $maxPerMinute;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function rateLimitMessage(string $imei): bool
    {
        return $this->rateLimitCheck("msg:$imei", 60);
    }

    public function rateLimitCommand(string $imei): bool
    {
        return $this->rateLimitCheck("cmd:$imei", 30);
    }
}
