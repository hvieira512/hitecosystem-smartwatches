<?php

namespace App\Redis;

use App\Log\Logger;

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
            Logger::channel('redis')->warning("The 'redis' extension is not available. Redis features are disabled.");
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
            Logger::channel('redis')->info("Connected to $host:$port (node: {$this->nodeId})");
        } catch (\Throwable $e) {
            $this->redis = null;
            $this->available = false;
            Logger::channel('redis')->warning("Redis connection unavailable (" . $e->getMessage() . ")");
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
            Logger::channel('redis')->error("deviceSetOnline: {$e->getMessage()}");
        }
    }

    public function deviceSetOffline(string $imei): void
    {
        if (!$this->available) return;
        try {
            $this->redis->hDel('device:online', $imei);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("deviceSetOffline: {$e->getMessage()}");
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
                    'native_type' => $event['nativeType'],
                    'feature' => $event['feature'] ?? '',
                    'native_payload' => json_encode($event['nativePayload']),
                    'received_at' => $event['receivedAt'],
                ],
                10000
            );
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("eventPush: {$e->getMessage()}");
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
                        'nativeType' => $data['native_type'],
                        'feature' => $data['feature'] ?: null,
                        'nativePayload' => json_decode($data['native_payload'], true) ?? [],
                        'receivedAt' => (int)$data['received_at'],
                    ];
                }
            }
            return $events;
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("readEvents: {$e->getMessage()}");
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
            Logger::channel('redis')->error("xGroupCreate: {$e->getMessage()}");
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
                        'nativeType' => $data['native_type'],
                        'feature' => $data['feature'] ?? '',
                        'nativePayload' => json_decode($data['native_payload'], true) ?? [],
                        'receivedAt' => (int)$data['received_at'],
                    ];
                }
            }
            return $events;
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("xReadGroup: {$e->getMessage()}");
            return [];
        }
    }

    public function xAck(string $stream, string $group, array $ids): int
    {
        if (!$this->available) return 0;
        try {
            return $this->redis->xAck($stream, $group, $ids);
        } catch (\Throwable $e) {
            Logger::channel('redis')->error("xAck: {$e->getMessage()}");
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
            Logger::channel('redis')->error("commandPublish: {$e->getMessage()}");
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
            Logger::channel('redis')->error("commandReadGroup: {$e->getMessage()}");
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
