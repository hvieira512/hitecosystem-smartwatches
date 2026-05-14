<?php

namespace App;

class Config
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(): self
    {
        return new self([
            'websocket' => [
                'host' => getenv('WS_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('WS_PORT') ?: 8080),
            ],
            'vivistar_tcp' => [
                'host' => getenv('VIVISTAR_TCP_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('VIVISTAR_TCP_PORT') ?: 9000),
            ],
            'api' => [
                'host' => getenv('API_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('API_PORT') ?: 8081),
            ],
            'database' => [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'name' => getenv('DB_NAME') ?: 'health_watches',
                'user' => getenv('DB_USER') ?: '',
                'pass' => getenv('DB_PASS') ?: '',
            ],
            'redis' => [
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
                'database' => 0,
            ],
            'public_ws_url' => getenv('WS_SERVER_URL') ?: 'ws://127.0.0.1:8080',
            'device_defaults' => [
                'allow_unknown_models' => false,
                'default_model' => null,
            ],
            'logging' => [
                'level' => getenv('LOG_LEVEL') ?: 'info',
                'file' => getenv('LOG_FILE') ?: 'var/log/server.log',
            ],
        ]);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
