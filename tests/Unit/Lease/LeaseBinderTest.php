<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Lease;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\Event\EventEmitter;
use SwooleDoctrinePool\Event\LeaseReleased;
use SwooleDoctrinePool\Exception\AcquireTimeoutException;
use SwooleDoctrinePool\Lease\Lease;
use SwooleDoctrinePool\Lease\LeaseBinder;
use SwooleDoctrinePool\Pool\CountingSlots;
use SwooleDoctrinePool\Pool\Pool;
use SwooleDoctrinePool\Pool\PoolKey;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeClock;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeConnectionFactory;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeCoroutineApi;
use SwooleDoctrinePool\Tests\Unit\Fake\RecordingDispatcher;

#[CoversClass(LeaseBinder::class)]
#[CoversClass(Lease::class)]
final class LeaseBinderTest extends TestCase
{
    private FakeCoroutineApi $api;
    private RecordingDispatcher $dispatcher;
    private LeaseBinder $binder;

    #[Override]
    protected function setUp(): void
    {
        $this->api = new FakeCoroutineApi(cid: 1);
        $this->dispatcher = new RecordingDispatcher();
        $this->binder = new LeaseBinder($this->api);
    }

    public function testAcquireBindsTheLeaseToTheCurrentCoroutineAndRegistersADefer(): void
    {
        $pool = $this->pool('a');

        $lease = $this->binder->acquire($pool);

        self::assertSame(1, $lease->ownerCid);
        self::assertSame($lease, $this->binder->current('a'));
        self::assertSame(1, $this->api->pendingDefers(1), 'Без defer соединение утечёт при исключении в обработчике');
    }

    public function testFinishingTheCoroutineReleasesTheLeaseThroughDefer(): void
    {
        $pool = $this->pool('a');
        $lease = $this->binder->acquire($pool);

        $this->api->finishCoroutine(1);

        self::assertTrue($lease->isReleased());
        self::assertSame(1, $pool->stats()->idle);
        self::assertTrue($this->dispatcher->of(LeaseReleased::class)[0]->viaDefer);
    }

    public function testCurrentDoesNotSeeLeasesOfOtherCoroutines(): void
    {
        $this->binder->acquire($this->pool('a'));

        $this->api->switchTo(2);

        self::assertNull($this->binder->current('a'));
    }

    public function testAnotherCoroutineGetsItsOwnPhysicalConnection(): void
    {
        $pool = $this->pool('a', size: 2);
        $first = $this->binder->acquire($pool);

        $this->api->switchTo(2);
        $second = $this->binder->acquire($pool);

        self::assertNotSame($first->pdo(), $second->pdo(), 'Одно PDO у двух корутин — гонка на сокете');
        self::assertSame(2, $pool->stats()->inUse);
    }

    public function testAnEarlierReleasedLeaseDoesNotUnbindItsSuccessor(): void
    {
        $pool = $this->pool('a');
        $first = $this->binder->acquire($pool);
        $first->release();

        $second = $this->binder->acquire($pool);
        $first->release();

        self::assertSame($second, $this->binder->current('a'));
        self::assertSame(2, $this->api->pendingDefers(1), 'У второго lease собственный defer');
    }

    public function testReleaseIsIdempotentTowardsThePool(): void
    {
        $pool = $this->pool('a');
        $lease = $this->binder->acquire($pool);

        $lease->release();
        $lease->release();

        self::assertSame(1, $pool->stats()->idle);
        self::assertSame(0, $pool->stats()->inUse, 'Повторный release не уменьшает inUse второй раз');
    }

    public function testReleaseAllCoversEveryPoolOfTheCoroutine(): void
    {
        $a = $this->pool('a');
        $b = $this->pool('b');
        $this->binder->acquire($a);
        $this->binder->acquire($b);

        self::assertSame(2, $this->binder->releaseAll());
        self::assertNull($this->binder->current('a'));
        self::assertNull($this->binder->current('b'));
        self::assertSame(1, $a->stats()->idle);
        self::assertSame(1, $b->stats()->idle);
    }

    public function testOutsideACoroutineThereIsNothingToBindOrRelease(): void
    {
        $this->api->leaveCoroutines();

        self::assertNull($this->binder->current('a'));
        self::assertSame(0, $this->binder->releaseAll());
    }

    public function testAFailedAcquireLeavesNoDeferBehind(): void
    {
        $pool = $this->pool('a', size: 1);
        $this->binder->acquire($pool);
        $this->api->switchTo(2);

        try {
            $this->binder->acquire($pool);
            self::fail('Ожидался таймаут');
        } catch (AcquireTimeoutException) {
        }

        self::assertSame(0, $this->api->pendingDefers(2));
        self::assertNull($this->binder->current('a'));
    }

    public function testMarkBrokenMakesReleaseDestroyTheConnection(): void
    {
        $pool = $this->pool('a');
        $lease = $this->binder->acquire($pool);

        $lease->markBroken();
        $lease->release();

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(1, $pool->stats()->closedTotal);
    }

    private function pool(string $hash, int $size = 3): Pool
    {
        $clock = new FakeClock();
        $config = new PoolConfig(size: $size);

        return new Pool(
            new PoolKey($hash, 'pool-' . $hash),
            $config,
            new FakeConnectionFactory($clock),
            new CountingSlots($size),
            $this->api,
            $clock,
            new EventEmitter($this->dispatcher),
        );
    }
}
