<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ConnectionClosePass implements CompilerPassInterface
{
    private const CONNECTION_ID = 'doctrine.dbal.swoole_connection';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CONNECTION_ID)) {
            return;
        }

        $doctrineConfig = $container->getDefinition(self::CONNECTION_ID)->getArgument(0);
        if (
            !isset($doctrineConfig['driverOptions']['useConnectionPool'])
            || $doctrineConfig['driverOptions']['useConnectionPool']
        ) {
            return;
        }

        $container
            ->register(ConnectionCloseSubscriber::class,ConnectionCloseSubscriber::class)
            ->addArgument(new Reference(self::CONNECTION_ID))
            ->addTag('kernel.event_subscriber');
    }
}
