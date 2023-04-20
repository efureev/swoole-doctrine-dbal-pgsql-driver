<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\DriverConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel;

final readonly class DriverMiddleware implements MiddlewareInterface
{
    private ConnectionPoolFactoryInterface $factory;
    private ContainerInterface $container;

    public function wrap(DriverInterface $driver): DriverInterface
    {
        if ($driver instanceof Driver) {
            if (!$this->container->has('connectionPool.keeper')) {
                $this->container->set('connectionPool.keeper', new ConnectionPoolKeeper());
            }
            /** @var ?ConnectionPoolKeeper $keeper */
            $keeper = $this->container->get('connectionPool.keeper');
            if (!$keeper instanceof ConnectionPoolKeeper) {
                throw new DriverConfigurationException('Invalid `connectionPool.keeper`');
            }

            $driver->setPoolKeeper($keeper);
            $driver->setPoolFactory($this->factory);
        }

        return $driver;
    }

    public function setFactory(ConnectionPoolFactoryInterface $factory)
    {
        $this->factory = $factory;
    }

    public function setKernel(Kernel $kernel)
    {
        $this->container = $kernel->getContainer();
    }
}
