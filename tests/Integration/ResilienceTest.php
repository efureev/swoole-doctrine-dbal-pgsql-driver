<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration;

use Doctrine\DBAL\Exception\ConnectionLost;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use SwooleDoctrinePool\Event\ConnectionClosed;
use SwooleDoctrinePool\Exception\PoolExhaustedException;
use SwooleDoctrinePool\Pool\CloseReason;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Tests\Integration\Support\CoroutineTestCase;
use SwooleDoctrinePool\Tests\Unit\Fake\RecordingDispatcher;

use function hrtime;

/**
 * Поведение под отказами: убитые бэкенды, исчерпание, закрытие пула, обслуживание.
 */
#[CoversNothing]
final class ResilienceTest extends CoroutineTestCase
{
    public function testAKilledBackendMidTransactionRaisesConnectionLostAndIsNeverReused(): void
    {
        $dispatcher = new RecordingDispatcher();

        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 2]);

            $connection->beginTransaction();
            $pid = self::backendPid($connection);
            self::db()->terminateBackend($pid);

            try {
                $connection->fetchOne('SELECT 1');
                self::fail('Запрос по убитому соединению должен упасть');
            } catch (ConnectionLost) {
            }

            self::assertFalse($connection->isTransactionActive(), 'Состояние транзакции ушло вместе с lease');
            self::assertNotSame($pid, self::backendPid($connection), 'Следующий запрос — на новом бэкенде');
            self::assertSame(1, self::pool($registry)->stats()->closedTotal);
        }, $dispatcher);

        $closed = $dispatcher->of(ConnectionClosed::class);
        self::assertSame(CloseReason::Broken, $closed[0]->reason);
    }

    public function testPoolExhaustionTimesOutWithoutBlockingTheReactor(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 1, 'acquire_timeout' => 0.3]);
            $ticks = 0;
            $group = new WaitGroup();

            $group->add();
            Coroutine::create(static function () use ($connection, $group): void {
                $connection->executeQuery('SELECT pg_sleep(0.6)');
                $connection->close();
                $group->done();
            });

            $group->add();
            Coroutine::create(static function () use ($group, &$ticks): void {
                for ($i = 0; $i < 10; $i++) {
                    Coroutine::sleep(0.05);
                    $ticks++;
                }

                $group->done();
            });

            Coroutine::sleep(0.05);
            $exhausted = null;
            $startedAt = hrtime(true);

            try {
                $connection->fetchOne('SELECT 1');
            } catch (PoolExhaustedException $e) {
                $exhausted = $e;
            }

            $waited = ((float)hrtime(true) - (float)$startedAt) / 1e9;
            $group->wait();

            self::assertInstanceOf(PoolExhaustedException::class, $exhausted);
            self::assertGreaterThan(0.25, $waited);
            self::assertLessThan(0.55, $waited, 'Ожидание должно закончиться по acquire_timeout, а не по освобождению');
            self::assertSame(10, $ticks, 'Пока одна корутина ждала слот, остальные обязаны работать');
            self::assertSame(1, self::pool($registry)->stats()->acquireTimeoutsTotal);
        });
    }

    public function testAWaiterIsWokenAsSoonAsAConnectionIsReleased(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 1, 'acquire_timeout' => 2.0]);
            $group = new WaitGroup();
            $group->add();

            Coroutine::create(static function () use ($connection, $group): void {
                $connection->fetchOne('SELECT 1');
                Coroutine::sleep(0.2);
                $connection->close();
                $group->done();
            });

            Coroutine::sleep(0.02);
            $startedAt = hrtime(true);
            self::assertSame(1, (int)$connection->fetchOne('SELECT 1'));
            $waited = ((float)hrtime(true) - (float)$startedAt) / 1e9;
            $group->wait();

            self::assertGreaterThan(0.1, $waited);
            self::assertLessThan(0.6, $waited, 'Ожидающий должен проснуться по release, а не по таймауту');
        });
    }

    public function testValidationDetectsAConnectionKilledWhileIdle(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 1, 'validate_idle_after' => 0]);

            $pid = self::backendPid($connection);
            $connection->close();
            self::db()->terminateBackend($pid);
            Coroutine::sleep(0.05);

            self::assertNotSame($pid, self::backendPid($connection), 'Убитое idle-соединение поймано проверкой, запрос прошёл без ошибки');
            self::assertSame(1, self::pool($registry)->stats()->validationFailuresTotal);
        });
    }

    public function testIdleConnectionsAboveMinIdleAreClosedByMaintenance(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, [
                'size' => 3,
                'min_idle' => 1,
                'idle_timeout' => 0.2,
                'maintenance_interval' => 0.1,
            ]);
            $group = new WaitGroup();

            for ($i = 0; $i < 3; $i++) {
                $group->add();
                Coroutine::create(static function () use ($connection, $group): void {
                    $connection->executeQuery('SELECT pg_sleep(0.1)');
                    // close() до done(): release уступает планировщику на DISCARD ALL, а defer сработал бы уже после wait().
                    $connection->close();
                    $group->done();
                });
            }

            $group->wait();
            self::assertSame(3, self::pool($registry)->stats()->idle);

            Coroutine::sleep(0.6);

            self::assertSame(1, self::pool($registry)->stats()->idle, 'Обслуживание оставило ровно min_idle');
            self::assertSame(2, self::pool($registry)->stats()->closedTotal);
        });
    }

    public function testMaxLifetimeRecyclesConnectionsUnderLoadWithoutErrors(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 2, 'max_lifetime' => 0.1]);
            $group = new WaitGroup();
            $errors = 0;

            for ($i = 0; $i < 6; $i++) {
                $group->add();
                Coroutine::create(static function () use ($connection, $group, &$errors): void {
                    for ($j = 0; $j < 5; $j++) {
                        try {
                            $connection->fetchOne('SELECT 1');
                            $connection->close();
                            Coroutine::sleep(0.03);
                        } catch (\Throwable) {
                            $errors++;
                        }
                    }

                    $group->done();
                });
            }

            $group->wait();

            self::assertSame(0, $errors);
            self::assertGreaterThan(2, self::pool($registry)->stats()->createdTotal, 'Соединения пересоздавались по max_lifetime');
            self::assertLessThanOrEqual(2, self::pool($registry)->stats()->open());
        });
    }

    public function testClosingThePoolWaitsForLeasesAndALaterQueryGetsAFreshPool(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 2]);
            $group = new WaitGroup();
            $group->add();

            Coroutine::create(static function () use ($connection, $group): void {
                $connection->fetchOne('SELECT 1');
                Coroutine::sleep(0.2);
                $connection->close();
                $group->done();
            });

            Coroutine::sleep(0.02);
            $startedAt = hrtime(true);
            $registry->closeAll(2.0);
            $waited = ((float)hrtime(true) - (float)$startedAt) / 1e9;
            $group->wait();

            self::assertGreaterThan(0.1, $waited, 'close ждёт занятое соединение');
            self::assertSame(0, $registry->count());

            // Закрытый пул не воскресает — следующий запрос получает новый (как вторая команда после console.terminate).
            self::assertSame(1, (int)$connection->fetchOne('SELECT 1'));
            self::assertSame(1, $registry->count());
            self::assertSame(1, self::pool($registry)->stats()->createdTotal, 'Свежий пул, а не закрытый');
        });
    }

    /** Отдельный процесс: после первого Coroutine\run() хуки остаются включёнными, и PDO вне корутины запрещён. */
    #[RunInSeparateProcess]
    public function testTheDriverWorksOutsideTheSchedulerInDirectMode(): void
    {
        $registry = new PoolRegistry();
        $connection = self::connection($registry);

        self::assertSame(1, (int)$connection->fetchOne('SELECT 1'));
        $connection->beginTransaction();
        self::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();
        $connection->close();
        self::assertSame(1, (int)$connection->fetchOne('SELECT 1'), 'После close соединение открывается заново');

        self::assertSame(0, $registry->count(), 'Вне корутины пул не создаётся');
        $connection->close();
    }
}
