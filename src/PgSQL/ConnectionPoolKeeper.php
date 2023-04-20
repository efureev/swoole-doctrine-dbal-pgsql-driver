<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

final class ConnectionPoolKeeper
{
    private ?ConnectionPoolInterface $pool = null;

    public function __construct()
    {
        var_dump(__METHOD__);
    }

    public function isEmpty(): bool
    {
        return $this->pool === null;
    }

    public function set(ConnectionPoolInterface $pool): void
    {
        $this->pool = $pool;
    }

    public function get(): ConnectionPoolInterface
    {
        if ($this->pool === null) {
            throw new \RuntimeException('Missing the Connection Pool');
        }

        return $this->pool;
    }
}
