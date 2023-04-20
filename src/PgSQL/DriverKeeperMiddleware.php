<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

final readonly class DriverKeeperMiddleware implements MiddlewareInterface
{
    private ConnectionPoolFactoryInterface $factory;
    private ConnectionPoolKeeper $keeper;

    public function __construct(ConnectionPoolKeeper $keeper = null)
    {
        $this->keeper = $keeper ?? new ConnectionPoolKeeper();
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        if ($driver instanceof Driver) {
            $driver->setPoolKeeper($this->keeper);
            $driver->setPoolFactory($this->factory);
        }

        return $driver;
    }

    public function setFactory(ConnectionPoolFactoryInterface $factory)
    {
        $this->factory = $factory;
    }
}
