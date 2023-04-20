<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL;

use Swoole\Packages\Doctrine\DBAL\PgSQL\ConnectionPoolFactory;
use Swoole\Packages\Doctrine\DBAL\PgSQL\DriverMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SwooleDoctrineDbalPoolBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container
            ->register(ConnectionPoolFactory::class)
            ->setArguments([new Reference('event_dispatcher')]);

        $container
            ->register(DriverMiddleware::class)
            ->addMethodCall('setFactory', [new Reference(ConnectionPoolFactory::class)])
            ->addMethodCall('setKernel', [new Reference('kernel')])
            ->addTag('doctrine.middleware');
    }
}
