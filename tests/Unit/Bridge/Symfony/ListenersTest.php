<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Bridge\Symfony;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Bridge\Symfony\EventListener\ClosePoolsListener;
use SwooleDoctrinePool\Bridge\Symfony\EventListener\ReleaseLeaseListener;
use SwooleDoctrinePool\Bridge\Symfony\EventListener\WarmUpListener;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Pool\CountingSlots;
use SwooleDoctrinePool\Pool\PoolKey;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Pool\Slots;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeClock;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeConnectionFactory;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeCoroutineApi;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeInnerDriver;
use SwooleDoctrinePool\Tests\Unit\Fake\RecordingLogger;

#[CoversClass(ReleaseLeaseListener::class)]
#[CoversClass(ClosePoolsListener::class)]
#[CoversClass(WarmUpListener::class)]
final class ListenersTest extends TestCase
{
    private FakeCoroutineApi $api;
    private FakeClock $clock;
    private PoolRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        $this->api = new FakeCoroutineApi(cid: 1);
        $this->clock = new FakeClock();
        $this->registry = new PoolRegistry(
            api: $this->api,
            clock: $this->clock,
            slotsFactory: static fn(int $size): Slots => new CountingSlots($size),
        );
    }

    public function testTerminateReleasesTheLeaseOfTheCurrentCoroutine(): void
    {
        $pool = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), new FakeConnectionFactory($this->clock));
        $this->registry->binder()->acquire($pool);

        (new ReleaseLeaseListener($this->registry))->onTerminate();

        self::assertSame(0, $pool->stats()->inUse);
        self::assertSame(1, $pool->stats()->idle);
    }

    public function testTerminateOutsideACoroutineIsHarmless(): void
    {
        $this->api->leaveCoroutines();

        (new ReleaseLeaseListener($this->registry))->onTerminate();

        self::assertSame(0, $this->registry->count());
    }

    public function testConsoleTerminateReleasesTheLeaseAndClosesThePoolsSoTheCommandCanExit(): void
    {
        $pool = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(maintenanceInterval: 5), new FakeConnectionFactory($this->clock));
        $this->registry->binder()->acquire($pool);

        (new ReleaseLeaseListener($this->registry))->onConsoleTerminate();

        self::assertTrue($pool->isClosed());
        self::assertCount(0, $this->api->timers, 'Живой таймер не дал бы Coroutine\run() завершиться');
        self::assertSame(0, $this->registry->count());
    }

    public function testWorkerExitDrainsPoolsWhileWorkerStopClosesThem(): void
    {
        $pool = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(maintenanceInterval: 5), new FakeConnectionFactory($this->clock));
        $lease = $this->registry->binder()->acquire($pool);
        $listener = new ClosePoolsListener($this->registry);

        $listener->onExit();
        $listener->onExit();

        self::assertTrue($pool->isDraining());
        self::assertFalse($pool->isClosed(), 'На worker_exit запросы в полёте ещё живы');
        self::assertCount(0, $this->api->timers);
        self::assertSame(1, $pool->stats()->inUse);

        $lease->release();
        $listener->onStop();

        self::assertTrue($pool->isClosed());
        self::assertSame(0, $this->registry->count());
    }

    public function testWorkerStopClosesEveryPoolAndCanBeRepeated(): void
    {
        $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), new FakeConnectionFactory($this->clock));
        $logger = new RecordingLogger();
        $listener = new ClosePoolsListener($this->registry, $logger);

        $listener->onStop();
        $listener->onStop();

        self::assertSame(0, $this->registry->count());
        self::assertCount(0, $this->api->timers);
        self::assertCount(1, $logger->messages('debug'), 'Повторный вызов без пулов молчит');
    }

    public function testWarmUpOpensMinIdleConnectionsForPooledConnectionsOnly(): void
    {
        $inner = new FakeInnerDriver();
        $warm = $this->connection($inner, ['pool' => ['min_idle' => 2, 'size' => 3]]);
        $cold = $this->connection($inner, ['pool' => ['min_idle' => 0]], dbname: 'cold');

        $listener = new WarmUpListener($this->doctrine(['warm' => $warm, 'cold' => $cold, 'plain' => null]), $this->registry, ['warm', 'cold', 'plain']);
        $listener->onWorkerStart();

        self::assertSame(1, $this->registry->count(), 'Пул создан только для соединения с min_idle > 0');
        self::assertSame(2, $this->registry->stats()['app@db:5432/app']->idle);
        self::assertCount(2, $inner->connections);
    }

    public function testWarmUpDoesNothingOutsideACoroutineOrWithoutDoctrine(): void
    {
        $inner = new FakeInnerDriver();
        $warm = $this->connection($inner, ['pool' => ['min_idle' => 1]]);

        (new WarmUpListener(null, $this->registry, ['warm']))->onWorkerStart();
        self::assertSame(0, $this->registry->count());

        $this->api->leaveCoroutines();
        (new WarmUpListener($this->doctrine(['warm' => $warm]), $this->registry, ['warm']))->onWorkerStart();
        self::assertSame(0, $this->registry->count());
    }

    public function testWarmUpFailuresAreLoggedNotThrown(): void
    {
        $logger = new RecordingLogger();
        $this->registry = new PoolRegistry(
            logger: $logger,
            api: $this->api,
            clock: $this->clock,
            slotsFactory: static fn(int $size): Slots => new CountingSlots($size),
        );
        $inner = new FakeInnerDriver();
        $inner->failWith = new \PDOException('could not connect');
        $warm = $this->connection($inner, ['pool' => ['min_idle' => 1]]);

        (new WarmUpListener($this->doctrine(['warm' => $warm]), $this->registry, ['warm']))->onWorkerStart();

        self::assertSame(0, $this->registry->stats()['app@db:5432/app']->idle);
        self::assertCount(1, $logger->messages('warning'));
    }

    /** @param array<string, mixed> $driverOptions */
    private function connection(FakeInnerDriver $inner, array $driverOptions, string $dbname = 'app'): CoroutineSafeConnection
    {
        return new CoroutineSafeConnection([
            'host' => 'db',
            'dbname' => $dbname,
            'user' => 'app',
            'serverVersion' => '17',
            'wrapperClass' => CoroutineSafeConnection::class,
            'driverOptions' => $driverOptions,
        ], new Driver($inner, $this->registry), null, $this->api);
    }

    /** @param array<string, ?Connection> $connections */
    private function doctrine(array $connections): ManagerRegistry
    {
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturnCallback(
            static fn(?string $name): object => $connections[$name] ?? new \stdClass(),
        );

        return $doctrine;
    }
}
