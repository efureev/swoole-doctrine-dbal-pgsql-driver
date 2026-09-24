<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Pool;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use PDOException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\Event\AcquireTimedOut;
use SwooleDoctrinePool\Event\ConnectionClosed;
use SwooleDoctrinePool\Event\EventEmitter;
use SwooleDoctrinePool\Event\PoolClosed;
use SwooleDoctrinePool\Event\RolledBackOnRelease;
use SwooleDoctrinePool\Exception\AcquireTimeoutException;
use SwooleDoctrinePool\Exception\PoolClosedException;
use SwooleDoctrinePool\Pool\CloseReason;
use SwooleDoctrinePool\Pool\CountingSlots;
use SwooleDoctrinePool\Pool\Pool;
use SwooleDoctrinePool\Pool\PoolKey;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeClock;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeConnectionFactory;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeCoroutineApi;
use SwooleDoctrinePool\Tests\Unit\Fake\RecordingDispatcher;
use SwooleDoctrinePool\Tests\Unit\Fake\RecordingLogger;

#[CoversClass(Pool::class)]
final class PoolTest extends TestCase
{
    private FakeClock $clock;
    private FakeConnectionFactory $factory;
    private FakeCoroutineApi $api;
    private CountingSlots $slots;
    private RecordingDispatcher $dispatcher;
    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->factory = new FakeConnectionFactory($this->clock);
        $this->api = new FakeCoroutineApi();
        $this->dispatcher = new RecordingDispatcher();
        $this->logger = new RecordingLogger();
    }

    public function testASlotIsReservedBeforeConnectingSoAConcurrentColdAcquireCannotOverProvision(): void
    {
        $pool = $this->pool(['size' => 1]);
        $nested = null;

        $this->factory->onOpen = function () use ($pool, &$nested): void {
            $this->factory->onOpen = null;

            // Пока первый коннект «висит», вторая корутина приходит за соединением.
            try {
                $pool->acquire();
            } catch (AcquireTimeoutException $e) {
                $nested = $e;
            }
        };

        $pool->acquire();

        self::assertInstanceOf(AcquireTimeoutException::class, $nested, 'Второй acquire обязан ждать слот, а не открывать соединение');
        self::assertSame(1, $pool->stats()->createdTotal, 'Открыто больше соединений, чем size');
    }

    public function testAFailedConnectReturnsTheSlotAndPropagatesTheException(): void
    {
        $pool = $this->pool(['size' => 2]);
        $this->factory->failWith = new PDOException('could not connect to server');

        try {
            $pool->acquire();
            self::fail('Ожидалось исключение подключения');
        } catch (PdoDriverException) {
        }

        self::assertSame(2, $this->slots->available(), 'Слот не вернулся после неудачного коннекта');
        self::assertSame(0, $pool->stats()->connecting);
        $this->assertInvariant($pool);
    }

    public function testReleaseRollsBackAnOpenTransactionThenDiscardsAndReportsIt(): void
    {
        $pool = $this->pool();
        $connection = $pool->acquire();
        $pdo = $this->factory->lastPdo();
        $pdo->inTransaction = true;

        $pool->release($connection, broken: false, viaDefer: true);

        self::assertSame(['rollBack', 'exec:DISCARD ALL'], $pdo->log, 'Сначала ROLLBACK, потом DISCARD ALL');
        self::assertSame(1, $pool->stats()->rollbacksOnReleaseTotal);
        self::assertSame(1, $pool->stats()->idle, 'После отката соединение пригодно и возвращается в пул');
        self::assertCount(1, $this->dispatcher->of(RolledBackOnRelease::class));
        self::assertTrue($this->dispatcher->of(RolledBackOnRelease::class)[0]->viaDefer);
        self::assertCount(1, $this->logger->messages('warning'));
    }

    public function testRollbackOnlyPolicySkipsDiscardAll(): void
    {
        $pool = $this->pool(['reset_on_release' => 'rollback_only']);
        $connection = $pool->acquire();
        $pdo = $this->factory->lastPdo();

        $pool->release($connection, broken: false);

        self::assertSame([], $pdo->log, 'Без транзакции и с rollback_only на возврате нет ни одного запроса');
        self::assertSame(1, $pool->stats()->idle);
    }

    public function testABrokenConnectionIsDestroyedInsteadOfReturnedToIdle(): void
    {
        $pool = $this->pool(['size' => 2]);
        $connection = $pool->acquire();

        $pool->release($connection, broken: true);

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(1, $pool->stats()->closedTotal);
        self::assertTrue($connection->isClosed());
        self::assertSame(2, $this->slots->available(), 'Слот уничтоженного соединения вернулся в семафор');
        self::assertSame(CloseReason::Broken, $this->dispatcher->of(ConnectionClosed::class)[0]->reason);
        $this->assertInvariant($pool);
    }

    public function testAFailingRollbackDestroysTheConnection(): void
    {
        $pool = $this->pool();
        $connection = $pool->acquire();
        $pdo = $this->factory->lastPdo();
        $pdo->inTransaction = true;
        $pdo->failOn['rollBack'] = new PDOException('server closed the connection unexpectedly');

        $pool->release($connection, broken: false);

        self::assertSame(0, $pool->stats()->idle, 'Соединение с неудавшимся откатом нельзя отдавать другим');
        self::assertSame(CloseReason::RollbackFailed, $this->dispatcher->of(ConnectionClosed::class)[0]->reason);
        $this->assertInvariant($pool);
    }

    public function testAFailingDiscardDestroysTheConnection(): void
    {
        $pool = $this->pool();
        $connection = $pool->acquire();
        $this->factory->lastPdo()->failOn['exec'] = new PDOException('boom');

        $pool->release($connection, broken: false);

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(CloseReason::ResetFailed, $this->dispatcher->of(ConnectionClosed::class)[0]->reason);
        $this->assertInvariant($pool);
    }

    public function testAConnectionPastMaxUsesIsDestroyedOnRelease(): void
    {
        $pool = $this->pool(['max_uses' => 2]);

        $first = $pool->acquire();
        $pool->release($first, broken: false);
        $again = $pool->acquire();
        self::assertSame($first, $again, 'Первый use не исчерпывает лимит');
        $pool->release($again, broken: false);

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(CloseReason::MaxUses, $this->dispatcher->of(ConnectionClosed::class)[0]->reason);
    }

    public function testAConnectionPastMaxLifetimeIsDestroyedOnAcquireAndReplaced(): void
    {
        $pool = $this->pool(['max_lifetime' => 100]);
        $first = $pool->acquire();
        $pool->release($first, broken: false);

        $this->clock->advance(101);
        $second = $pool->acquire();

        self::assertNotSame($first, $second);
        self::assertTrue($first->isClosed());
        self::assertSame(2, $pool->stats()->createdTotal);
        self::assertSame(CloseReason::MaxLifetime, $this->dispatcher->of(ConnectionClosed::class)[0]->reason);
        $this->assertInvariant($pool);
    }

    public function testValidationRunsOnlyForConnectionsIdleLongerThanThreshold(): void
    {
        $pool = $this->pool(['validate_idle_after' => 5]);
        $connection = $pool->acquire();
        $pdo = $this->factory->lastPdo();
        $pool->release($connection, broken: false);
        $pdo->log = [];

        $this->clock->advance(1);
        $pool->release($pool->acquire(), broken: false);
        self::assertNotContains('query:SELECT 1', $pdo->log, 'Свежее соединение не проверяется');

        $pdo->log = [];
        $this->clock->advance(6);
        $pool->acquire();
        self::assertContains('query:SELECT 1', $pdo->log, 'Долго простоявшее соединение проверяется');
    }

    public function testValidateIdleAfterZeroMeansAlwaysAndNullMeansNever(): void
    {
        $always = $this->pool(['validate_idle_after' => 0]);
        $always->release($always->acquire(), broken: false);
        $pdo = $this->factory->lastPdo();
        $pdo->log = [];
        $always->acquire();
        self::assertContains('query:SELECT 1', $pdo->log);

        $this->setUp();
        $never = $this->pool(['validate_idle_after' => null]);
        $never->release($never->acquire(), broken: false);
        $pdo = $this->factory->lastPdo();
        $pdo->log = [];
        $this->clock->advance(3600);
        $never->acquire();
        self::assertNotContains('query:SELECT 1', $pdo->log);
    }

    public function testAValidationFailureDestroysTheIdleConnectionAndOpensAFreshOne(): void
    {
        $pool = $this->pool(['validate_idle_after' => 0]);
        $first = $pool->acquire();
        $pool->release($first, broken: false);
        $this->factory->lastPdo()->failOn['query'] = new PDOException('server closed the connection unexpectedly');

        $second = $pool->acquire();

        self::assertNotSame($first, $second);
        self::assertTrue($first->isClosed());
        self::assertSame(1, $pool->stats()->validationFailuresTotal);
        self::assertSame(CloseReason::ValidationFailed, $this->dispatcher->of(ConnectionClosed::class)[0]->reason);
        $this->assertInvariant($pool);
    }

    public function testTheIdleStackIsLifo(): void
    {
        $pool = $this->pool(['size' => 2]);
        $a = $pool->acquire();
        $b = $pool->acquire();
        $pool->release($a, broken: false);
        $pool->release($b, broken: false);

        self::assertSame($b, $pool->acquire(), 'Последнее возвращённое соединение выдаётся первым — оно самое тёплое');
    }

    public function testMaintainClosesIdleConnectionsBeyondMinIdleAndNeverBelowIt(): void
    {
        $pool = $this->pool(['size' => 5, 'min_idle' => 1, 'idle_timeout' => 10]);
        $connections = [$pool->acquire(), $pool->acquire(), $pool->acquire()];

        foreach ($connections as $connection) {
            $pool->release($connection, broken: false);
        }

        $this->clock->advance(11);
        $pool->maintain();

        self::assertSame(1, $pool->stats()->idle);
        self::assertSame(2, $pool->stats()->closedTotal);
        self::assertSame(CloseReason::IdleTimeout, $this->dispatcher->of(ConnectionClosed::class)[0]->reason);
        $this->assertInvariant($pool);
    }

    public function testMaintainTopsUpToMinIdleWithoutExceedingSize(): void
    {
        $pool = $this->pool(['size' => 2, 'min_idle' => 2]);
        $held = $pool->acquire();

        $pool->maintain();

        self::assertSame(1, $pool->stats()->idle, 'Один занят, один прогрет — итого size');
        self::assertSame(2, $pool->stats()->createdTotal);
        $pool->release($held, broken: false);
        $this->assertInvariant($pool);
    }

    public function testMaintainRemovesExpiredConnectionsFromTheStackBeforeClosingThem(): void
    {
        $pool = $this->pool(['size' => 3, 'max_lifetime' => 50]);
        $old = $pool->acquire();
        $pool->release($old, broken: false);
        $this->clock->advance(60);
        $fresh = $pool->acquire();
        $pool->release($fresh, broken: false);

        $pool->maintain();

        self::assertTrue($old->isClosed());
        self::assertFalse($fresh->isClosed());
        self::assertSame($fresh, $pool->acquire());
        $this->assertInvariant($pool);
    }

    public function testTheAccountingInvariantHoldsAcrossRandomizedOperations(): void
    {
        mt_srand(20260923);
        $pool = $this->pool(['size' => 4, 'min_idle' => 1, 'max_uses' => 5, 'idle_timeout' => 30, 'max_lifetime' => 200]);
        $held = [];

        for ($step = 0; $step < 400; $step++) {
            switch (mt_rand(0, 5)) {
                case 0:
                case 1:
                    try {
                        $held[] = $pool->acquire();
                    } catch (AcquireTimeoutException) {
                    }
                    break;
                case 2:
                case 3:
                    if ($held !== []) {
                        $index = array_rand($held);
                        $connection = $held[$index];
                        unset($held[$index]);
                        $this->factory->pdos[$connection->id - 1]->inTransaction = mt_rand(0, 3) === 0;
                        $pool->release($connection, broken: mt_rand(0, 4) === 0);
                    }
                    break;
                case 4:
                    $this->clock->advance(mt_rand(0, 40));
                    break;
                case 5:
                    $pool->maintain();
                    break;
            }

            $this->assertInvariant($pool, "шаг {$step}");
        }
    }

    public function testCloseDrainsIdleDestroysInUseOnReleaseAndRejectsNewAcquires(): void
    {
        $pool = $this->pool(['size' => 3, 'maintenance_interval' => 10]);
        $held = $pool->acquire();
        $pool->release($pool->acquire(), broken: false);
        self::assertCount(1, $this->api->timers);

        $pool->close(drainTimeout: 0.0);

        self::assertSame(0, $pool->stats()->idle, 'Простаивающие закрыты сразу');
        self::assertCount(0, $this->api->timers, 'Таймер обслуживания снят — иначе воркер ждёт max_wait_time');
        self::assertCount(1, $this->dispatcher->of(PoolClosed::class));

        $pool->release($held, broken: false);
        self::assertTrue($held->isClosed(), 'Занятое на момент close соединение уничтожается при возврате');

        $this->expectException(PoolClosedException::class);
        $pool->acquire();
    }

    public function testCloseIsIdempotent(): void
    {
        $pool = $this->pool();
        $pool->close(0.0);
        $pool->close(0.0);

        self::assertCount(1, $this->dispatcher->of(PoolClosed::class));
    }

    public function testAcquireTimeoutEmitsAnEventWithTheCurrentStats(): void
    {
        $pool = $this->pool(['size' => 1]);
        $pool->acquire();

        try {
            $pool->acquire();
            self::fail('Ожидался таймаут');
        } catch (AcquireTimeoutException $e) {
            self::assertSame('08001', $e->getSQLState());
        }

        $event = $this->dispatcher->of(AcquireTimedOut::class)[0];
        self::assertSame(1, $event->stats->inUse);
        self::assertSame(1, $pool->stats()->acquireTimeoutsTotal);
        self::assertSame(0, $this->slots->available());
    }

    public function testTheMaintenanceTimerUsesTheConfiguredInterval(): void
    {
        $this->pool(['maintenance_interval' => 7]);

        self::assertSame(7.0, $this->api->timers[1][0]);
    }

    public function testNoTimerIsRegisteredByDefault(): void
    {
        $this->pool();

        self::assertCount(0, $this->api->timers, 'Живой таймер не даёт завершиться команде, демону и воркеру');
    }

    public function testReleaseLazilyClosesIdleConnectionsThatOutlivedIdleTimeoutAboveMinIdle(): void
    {
        $pool = $this->pool(['size' => 4, 'min_idle' => 1, 'idle_timeout' => 10]);
        $held = [$pool->acquire(), $pool->acquire(), $pool->acquire()];
        $pool->release($held[0], broken: false);
        $pool->release($held[1], broken: false);

        $this->clock->advance(11);
        $pool->release($held[2], broken: false);

        self::assertSame(1, $pool->stats()->idle, 'Два старых закрыты при возврате третьего, min_idle сохранён');
        self::assertTrue($held[0]->isClosed());
        self::assertTrue($held[1]->isClosed());
        self::assertFalse($held[2]->isClosed(), 'Только что возвращённое — самое тёплое, остаётся');
        $this->assertInvariant($pool);
    }

    public function testLazySweepClosesLifetimeExpiredConnectionsEvenBelowMinIdle(): void
    {
        $pool = $this->pool(['size' => 2, 'min_idle' => 2, 'max_lifetime' => 50]);
        $old = $pool->acquire();
        $pool->release($old, broken: false);
        $this->clock->advance(60);
        $fresh = $pool->acquire();
        $pool->release($fresh, broken: false);

        self::assertTrue($old->isClosed());
        self::assertSame(1, $pool->stats()->idle);
        $this->assertInvariant($pool);
    }

    public function testDrainClosesIdleStopsTheTimerAndKeepsServingInFlightRequests(): void
    {
        $pool = $this->pool(['size' => 3, 'maintenance_interval' => 10]);
        $held = $pool->acquire();
        $pool->release($pool->acquire(), broken: false);

        $pool->drain();

        self::assertSame(0, $pool->stats()->idle, 'Простаивающие закрыты');
        self::assertCount(0, $this->api->timers, 'Таймер снят — иначе воркер ждёт max_wait_time');
        self::assertTrue($pool->isDraining());

        $late = $pool->acquire();
        self::assertNotSame($held, $late, 'Запрос в полёте после drain всё ещё получает соединение');

        $pool->release($held, broken: false);
        self::assertTrue($held->isClosed(), 'Возвращённое при drain закрывается, а не копится');
        self::assertSame(CloseReason::Drained, $this->dispatcher->of(ConnectionClosed::class)[1]->reason);

        $pool->release($late, broken: false);
        $this->assertInvariant($pool);
        $pool->close(0.0);
        self::assertTrue($pool->isClosed());
    }

    public function testDrainIsIdempotentAndCloseAfterDrainRejectsAcquires(): void
    {
        $pool = $this->pool();
        $pool->drain();
        $pool->drain();
        $pool->close(0.0);

        $this->expectException(PoolClosedException::class);
        $pool->acquire();
    }

    public function testTheServerVersionIsCachedFromTheFirstConnection(): void
    {
        $pool = $this->pool();
        self::assertNull($pool->serverVersion());
        $pool->acquire();
        self::assertSame('17.2', $pool->serverVersion());
    }

    /** @param array<string, mixed> $config */
    private function pool(array $config = []): Pool
    {
        $poolConfig = PoolConfig::fromArray($config);
        $this->slots = new CountingSlots($poolConfig->size);

        return new Pool(
            new PoolKey('hash', 'app@db:5432/app'),
            $poolConfig,
            $this->factory,
            $this->slots,
            $this->api,
            $this->clock,
            new EventEmitter($this->dispatcher, $this->logger),
        );
    }

    private function assertInvariant(Pool $pool, string $context = ''): void
    {
        $stats = $pool->stats();
        self::assertSame(
            $stats->size,
            $stats->inUse + $stats->connecting + $this->slots->available(),
            'Нарушен инвариант токенов: inUse + connecting + свободные слоты != size ' . $context,
        );
        self::assertLessThanOrEqual(
            $stats->size,
            $stats->open(),
            'Открыто больше соединений, чем size ' . $context,
        );
    }
}
