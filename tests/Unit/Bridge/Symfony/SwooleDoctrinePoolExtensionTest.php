<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Bridge\Symfony;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Bridge\Symfony\DependencyInjection\SwooleDoctrinePoolExtension;
use SwooleDoctrinePool\Bridge\Symfony\EventListener\ClosePoolsListener;
use SwooleDoctrinePool\Bridge\Symfony\EventListener\ReleaseLeaseListener;
use SwooleDoctrinePool\Bridge\Symfony\EventListener\WarmUpListener;
use SwooleDoctrinePool\Driver\PoolDriverMiddleware;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Stats\PoolStatsProviderInterface;
use SwooleDoctrinePool\Stats\RegistryStatsProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(SwooleDoctrinePoolExtension::class)]
final class SwooleDoctrinePoolExtensionTest extends TestCase
{
    public function testTheAliasIsSwooleDoctrinePool(): void
    {
        self::assertSame('swoole_doctrine_pool', (new SwooleDoctrinePoolExtension())->getAlias());
    }

    public function testTheRegistryIsRegisteredWithNullSafeLoggerAndDispatcher(): void
    {
        $container = $this->load([]);
        $definition = $container->getDefinition(PoolRegistry::class);

        foreach (['$dispatcher' => 'event_dispatcher', '$logger' => 'logger'] as $argument => $service) {
            $reference = $definition->getArgument($argument);
            self::assertInstanceOf(Reference::class, $reference);
            self::assertSame($service, (string)$reference);
            self::assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $reference->getInvalidBehavior());
        }

        self::assertSame([['channel' => 'swoole_pool']], $definition->getTag('monolog.logger'));
    }

    public function testPoolOptionsPerEnabledConnectionAreExposedAsParameters(): void
    {
        $container = $this->load([
            'connections' => ['default' => ['size' => 3], 'legacy' => ['enabled' => false], 'other' => null],
        ]);

        $pooled = $container->getParameter(SwooleDoctrinePoolExtension::CONNECTIONS_PARAMETER);

        self::assertIsArray($pooled);
        self::assertSame(['default', 'other'], array_keys($pooled));
        self::assertArrayNotHasKey('enabled', $pooled['default']);
        self::assertSame(3, $pooled['default']['size']);
        self::assertSame(['default', 'other'], $container->getParameter(SwooleDoctrinePoolExtension::CONNECTION_NAMES_PARAMETER));
    }

    public function testTheMiddlewareIsTaggedOncePerEnabledConnectionWithHighPriority(): void
    {
        $container = $this->load(['connections' => ['a' => null, 'b' => null, 'c' => ['enabled' => false]]]);

        $tags = $container->getDefinition(PoolDriverMiddleware::class)->getTag('doctrine.middleware');

        self::assertSame([
            ['connection' => 'a', 'priority' => SwooleDoctrinePoolExtension::MIDDLEWARE_PRIORITY],
            ['connection' => 'b', 'priority' => SwooleDoctrinePoolExtension::MIDDLEWARE_PRIORITY],
        ], $tags);
        self::assertGreaterThan(10, SwooleDoctrinePoolExtension::MIDDLEWARE_PRIORITY, 'Выше middleware DoctrineBundle — пул должен быть самым внутренним');
    }

    public function testNoMiddlewareIsRegisteredWithoutPooledConnections(): void
    {
        self::assertFalse($this->load([])->hasDefinition(PoolDriverMiddleware::class));
        self::assertFalse($this->load(['connections' => ['x' => false]])->hasDefinition(PoolDriverMiddleware::class));
    }

    public function testTheTerminateListenerCoversKernelAndConsoleAfterEverybodyElse(): void
    {
        $tags = $this->load([])->getDefinition(ReleaseLeaseListener::class)->getTag('kernel.event_listener');

        self::assertSame([
            ['event' => 'kernel.terminate', 'method' => 'onTerminate', 'priority' => -1024],
            ['event' => 'console.terminate', 'method' => 'onConsoleTerminate', 'priority' => -1024],
        ], $tags);
    }

    public function testTheClosePoolsListenerSubscribesToTheSwooleRuntimeEventsByName(): void
    {
        $tags = $this->load([])->getDefinition(ClosePoolsListener::class)->getTag('kernel.event_listener');

        self::assertSame(
            [
                ['event' => 'swoole.worker_exit', 'method' => 'onExit'],
                ['event' => 'swoole.worker_stop', 'method' => 'onStop'],
                ['event' => 'swoole.before_shutdown', 'method' => 'onStop'],
            ],
            $tags,
        );
    }

    public function testTheWarmUpListenerRunsOnWorkerStart(): void
    {
        $tags = $this->load([])->getDefinition(WarmUpListener::class)->getTag('kernel.event_listener');

        self::assertSame([['event' => 'swoole.worker_start', 'method' => 'onWorkerStart']], $tags);
    }

    public function testTheStatsProviderInterfaceResolvesToTheRegistryProvider(): void
    {
        $definition = $this->load([])->getDefinition(PoolStatsProviderInterface::class);

        self::assertSame(RegistryStatsProvider::class, $definition->getClass());
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new SwooleDoctrinePoolExtension())->load([$config], $container);

        return $container;
    }
}
