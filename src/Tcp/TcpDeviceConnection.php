<?php

namespace App\Tcp;

use Ratchet\ConnectionInterface;
use React\Socket\ConnectionInterface as ReactConnection;

class TcpDeviceConnection implements ConnectionInterface
{
    public int $resourceId;
    private ReactConnection $connection;

    public function __construct(ReactConnection $connection, int $resourceId)
    {
        $this->connection = $connection;
        $this->resourceId = $resourceId;
    }

    public function send($data)
    {
        $this->connection->write((string)$data);
        return $this;
    }

    public function close()
    {
        $this->connection->end();
        return $this;
    }
}
