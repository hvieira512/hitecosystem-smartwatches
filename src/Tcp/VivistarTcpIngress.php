<?php

namespace App\Tcp;

use App\Log\Logger;
use App\WebSocket\WatchServer;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface as ReactConnection;
use React\Socket\Server as Reactor;

class VivistarTcpIngress
{
    private WatchServer $watchServer;
    private Reactor $socket;

    /** @var array<int, string> */
    private array $buffers = [];

    private int $nextResourceId = 1000000;

    public function __construct(WatchServer $watchServer, LoopInterface $loop, string $host, int $port)
    {
        $this->watchServer = $watchServer;
        $this->socket = new Reactor("$host:$port", $loop);

        $this->socket->on('connection', function (ReactConnection $connection): void {
            $this->onConnection($connection);
        });

        Logger::channel('app')->info("Vivistar TCP ingress at tcp://$host:$port");
    }

    private function onConnection(ReactConnection $connection): void
    {
        $resourceId = $this->nextResourceId++;
        $client = new TcpDeviceConnection($connection, $resourceId);

        $this->buffers[$resourceId] = '';
        $this->watchServer->onOpen($client);

        $connection->on('data', function ($data) use ($client, $resourceId): void {
            $this->onData($client, $resourceId, (string)$data);
        });

        $connection->on('close', function () use ($client, $resourceId): void {
            unset($this->buffers[$resourceId]);
            $this->watchServer->onClose($client);
        });

        $connection->on('error', function (\Throwable $error) use ($client): void {
            $exception = $error instanceof \Exception
                ? $error
                : new \RuntimeException($error->getMessage(), 0, $error);
            $this->watchServer->onError($client, $exception);
        });
    }

    private function onData(TcpDeviceConnection $client, int $resourceId, string $data): void
    {
        $this->buffers[$resourceId] = ($this->buffers[$resourceId] ?? '') . $data;

        while (($pos = strpos($this->buffers[$resourceId], '#')) !== false) {
            $packet = substr($this->buffers[$resourceId], 0, $pos + 1);
            $this->buffers[$resourceId] = substr($this->buffers[$resourceId], $pos + 1);

            $trimmed = trim($packet);
            if ($trimmed === '') {
                continue;
            }

            $this->watchServer->onMessage($client, $trimmed);
        }

        if (strlen($this->buffers[$resourceId]) > 65535) {
            Logger::channel('watch')->warning("TCP buffer overflow for resourceId=$resourceId; resetting buffer");
            $this->buffers[$resourceId] = '';
        }
    }
}
