<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Bridge\Symfony\DependencyInjection\Compiler;

use Override;
use SwooleDoctrinePool\Bridge\Symfony\DependencyInjection\SwooleDoctrinePoolExtension;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function implode;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Переводит выбранные DBAL-соединения DoctrineBundle на пул: подставляет driverClass, wrapperClass
 * и опции пула в параметры соединения. Пользователь в doctrine.yaml ничего про пул не пишет.
 */
final class PoolConnectionPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(SwooleDoctrinePoolExtension::CONNECTIONS_PARAMETER)) {
            return;
        }

        /** @var array<string, array<string, mixed>> $pooled */
        $pooled = $container->getParameter(SwooleDoctrinePoolExtension::CONNECTIONS_PARAMETER);

        if ($pooled === []) {
            return;
        }

        if (!$container->hasParameter('doctrine.connections')) {
            throw new InvalidConfigurationException(
                'swoole_doctrine_pool.connections задан, но DoctrineBundle не зарегистрирован: '
                . 'параметр doctrine.connections отсутствует.'
            );
        }

        /** @var array<string, string> $known имя => id сервиса */
        $known = $container->getParameter('doctrine.connections');

        foreach ($pooled as $name => $options) {
            $this->patch($container, $name, $options, $known);
        }
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, string> $known
     */
    private function patch(ContainerBuilder $container, string $name, array $options, array $known): void
    {
        $path = 'swoole_doctrine_pool.connections.' . $name;

        if (!isset($known[$name])) {
            throw new InvalidConfigurationException(sprintf(
                '%s: соединение "%s" не найдено в doctrine.dbal.connections. Известные: %s.',
                $path,
                $name,
                implode(', ', array_keys($known)),
            ));
        }

        $definition = $container->getDefinition($known[$name]);
        $arguments = $definition->getArguments();
        $params = $arguments[0] ?? null;

        if (!is_array($params)) {
            throw new InvalidConfigurationException(sprintf(
                '%s: у сервиса %s неожиданная структура аргументов (ожидался массив параметров DBAL первым '
                . 'аргументом) — несовместимая версия DoctrineBundle.',
                $path,
                $known[$name],
            ));
        }

        if (isset($params['driverClass']) && $params['driverClass'] !== Driver::class) {
            throw new InvalidConfigurationException(sprintf(
                '%s: уберите driver_class из doctrine.dbal.connections.%s — его подставляет бандл.',
                $path,
                $name,
            ));
        }

        $wrapperClass = $params['wrapperClass'] ?? null;

        $wrapperIsSafe = is_string($wrapperClass) && is_a($wrapperClass, CoroutineSafeConnection::class, true);

        if ($wrapperClass !== null && !$wrapperIsSafe) {
            throw new InvalidConfigurationException(sprintf(
                '%s: wrapper_class соединения "%s" должен наследовать %s.',
                $path,
                $name,
                CoroutineSafeConnection::class,
            ));
        }

        if (isset($params['replica']) || isset($params['primary'])) {
            throw new InvalidConfigurationException(sprintf(
                '%s: replicas (primary/replica) не поддерживаются пулом.',
                $path,
            ));
        }

        $driverOptions = $params['driverOptions'] ?? [];

        if (!is_array($driverOptions)) {
            $driverOptions = [];
        }

        if (isset($driverOptions[PoolConfig::OPTIONS_KEY])) {
            throw new InvalidConfigurationException(sprintf(
                '%s: опции пула задаются в swoole_doctrine_pool, а не в doctrine.dbal.connections.%s.options.%s.',
                $path,
                $name,
                PoolConfig::OPTIONS_KEY,
            ));
        }

        // При url: DoctrineBundle выбрасывает driverClass в рантайме; пул тогда гарантирует
        // PoolDriverMiddleware, а driverClass здесь нужен для пути без url и для debug:container.
        $params['driverClass'] = Driver::class;
        $params['wrapperClass'] = $wrapperClass ?? CoroutineSafeConnection::class;
        $driverOptions[PoolConfig::OPTIONS_KEY] = $options;
        $params['driverOptions'] = $driverOptions;

        $arguments[0] = $params;
        $definition->setArguments($arguments);
        $definition->setClass($params['wrapperClass']);
    }
}
