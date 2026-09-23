<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Bridge\Symfony\DependencyInjection;

use Override;
use SwooleDoctrinePool\Driver\PoolDriverMiddleware;
use SwooleDoctrinePool\Pool\PoolRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function dirname;

final class SwooleDoctrinePoolExtension extends Extension
{
    public const string ALIAS = Configuration::ALIAS;

    /** Пул должен быть самым внутренним middleware: у middleware DoctrineBundle priority 10. */
    public const int MIDDLEWARE_PRIORITY = 1024;

    /** Параметр контейнера: имя соединения => опции пула (только включённые соединения). */
    public const string CONNECTIONS_PARAMETER = 'swoole_doctrine_pool.connections';

    /** Параметр контейнера: список имён включённых соединений. */
    public const string CONNECTION_NAMES_PARAMETER = 'swoole_doctrine_pool.connection_names';

    public const string LOG_CHANNEL = 'swoole_pool';

    #[Override]
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /** @param array<array-key, mixed> $configs */
    #[Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 4) . '/config'));
        $loader->load('services.yaml');

        /** @var array{connections: array<string, array<string, mixed>>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $pooled = $this->pooledConnections($config['connections']);
        $container->setParameter(self::CONNECTIONS_PARAMETER, $pooled);
        $container->setParameter(self::CONNECTION_NAMES_PARAMETER, array_keys($pooled));

        $this->registerMiddleware($container, array_keys($pooled));
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     *
     * @return array<string, array<string, mixed>>
     */
    private function pooledConnections(array $connections): array
    {
        $pooled = [];

        foreach ($connections as $name => $options) {
            if (($options['enabled'] ?? true) === false) {
                continue;
            }

            unset($options['enabled']);
            $pooled[$name] = $options;
        }

        return $pooled;
    }

    /** @param list<string> $names */
    private function registerMiddleware(ContainerBuilder $container, array $names): void
    {
        if ($names === []) {
            return;
        }

        $middleware = $container
            ->register(PoolDriverMiddleware::class, PoolDriverMiddleware::class)
            ->setArguments(['$registry' => new Reference(PoolRegistry::class)]);

        foreach ($names as $name) {
            $middleware->addTag('doctrine.middleware', [
                'connection' => $name,
                'priority' => self::MIDDLEWARE_PRIORITY,
            ]);
        }
    }
}
