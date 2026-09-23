<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Pool;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\Pool\CountingSlots;
use SwooleDoctrinePool\Pool\PoolKey;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Pool\Slots;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeClock;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeConnectionFactory;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeCoroutineApi;

#[CoversClass(PoolRegistry::class)]
final class PoolRegistryTest extends TestCase
{
    private FakeCoroutineApi $api;
    private FakeClock $clock;
    private PoolRegistry $registry;

    #[Override]
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

    public function testTheSameKeySharesAPoolAndDifferentKeysDoNot(): void
    {
        $a = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), $this->factory());
        $again = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), $this->factory());
        $b = $this->registry->poolFor(new PoolKey('b', 'b'), new PoolConfig(), $this->factory());

        self::assertSame($a, $again);
        self::assertNotSame($a, $b);
        self::assertSame(2, $this->registry->count());
    }

    public function testCloseAllClosesEveryPoolClearsTimersAndIsIdempotent(): void
    {
        $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(maintenanceInterval: 5), $this->factory());
        $this->registry->poolFor(new PoolKey('b', 'b'), new PoolConfig(maintenanceInterval: 5), $this->factory());
        self::assertCount(2, $this->api->timers);

        $this->registry->closeAll(0.0);
        $this->registry->closeAll(0.0);

        self::assertCount(0, $this->api->timers);
        self::assertSame(0, $this->registry->count());
    }

    public function testAClosedPoolIsReplacedOnNextRequest(): void
    {
        $pool = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), $this->factory());
        $pool->close(0.0);

        $fresh = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), $this->factory());

        self::assertNotSame($pool, $fresh);
        self::assertFalse($fresh->isClosed());
    }

    public function testStatsAreKeyedByPoolLabel(): void
    {
        $this->registry->poolFor(new PoolKey('a', 'app@db/app'), new PoolConfig(size: 2), $this->factory());

        $stats = $this->registry->stats();

        self::assertArrayHasKey('app@db/app', $stats);
        self::assertSame(2, $stats['app@db/app']->size);
    }

    public function testReleaseCurrentCoroutineReleasesLeasesInEveryPool(): void
    {
        $a = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), $this->factory());
        $b = $this->registry->poolFor(new PoolKey('b', 'b'), new PoolConfig(), $this->factory());
        $this->registry->binder()->acquire($a);
        $this->registry->binder()->acquire($b);

        self::assertSame(2, $this->registry->releaseCurrentCoroutine());
        self::assertSame(0, $a->stats()->inUse);
        self::assertSame(0, $b->stats()->inUse);
    }

    public function testDrainAllDrainsEveryPoolWithoutClosingIt(): void
    {
        $a = $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), $this->factory());
        $lease = $this->registry->binder()->acquire($a);

        $this->registry->drainAll();

        self::assertTrue($a->isDraining());
        self::assertFalse($a->isClosed());
        self::assertSame(1, $this->registry->count());
        $lease->release();
        self::assertSame(0, $a->stats()->idle, 'При drain возвращённое соединение закрывается');
    }

    public function testADisabledPdoHookIsLoggedOnceAndThePoolStillWorks(): void
    {
        $logger = new \SwooleDoctrinePool\Tests\Unit\Fake\RecordingLogger();
        $this->api->hookEnabled = false;
        $registry = new PoolRegistry(
            logger: $logger,
            api: $this->api,
            clock: $this->clock,
            slotsFactory: static fn(int $size): Slots => new CountingSlots($size),
        );

        $pool = $registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(), $this->factory());
        $registry->poolFor(new PoolKey('b', 'b'), new PoolConfig(), $this->factory());
        $pool->acquire();

        self::assertCount(1, $logger->messages('warning'), 'Один warning на реестр, не на каждый пул');
        self::assertStringContainsString('SWOOLE_HOOK_PDO_PGSQL', $logger->messages('warning')[0]);
    }

    public function testWarmUpAllFillsEveryPoolToMinIdle(): void
    {
        $this->registry->poolFor(new PoolKey('a', 'a'), new PoolConfig(size: 3, minIdle: 2), $this->factory());

        $this->registry->warmUpAll();

        self::assertSame(2, $this->registry->stats()['a']->idle);
    }

    private function factory(): FakeConnectionFactory
    {
        return new FakeConnectionFactory($this->clock);
    }
}
