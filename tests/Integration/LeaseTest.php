<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use SwooleDoctrinePool\Event\RolledBackOnRelease;
use SwooleDoctrinePool\Exception\LeaseViolationException;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Tests\Integration\Support\CoroutineTestCase;
use SwooleDoctrinePool\Tests\Unit\Fake\RecordingDispatcher;

use function array_unique;
use function count;

/**
 * Привязка соединения к корутине и гарантии на release — то, ради чего пакет существует.
 */
#[CoversNothing]
final class LeaseTest extends CoroutineTestCase
{
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$database?->exec('CREATE TABLE ' . self::table('items') . ' (id serial PRIMARY KEY, marker text NOT NULL)');
    }

    public function testASingleCoroutineReusesOneBackendForAllItsQueries(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry);

            $pids = [self::backendPid($connection), self::backendPid($connection), self::backendPid($connection)];

            self::assertCount(1, array_unique($pids));
            self::assertSame(1, self::pool($registry)->stats()->createdTotal);
            self::assertSame(1, self::pool($registry)->stats()->inUse);
        });
    }

    public function testConcurrentCoroutinesNeverShareABackend(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 5]);
            $group = new WaitGroup();
            $pids = [];

            for ($i = 0; $i < 5; $i++) {
                $group->add();
                Coroutine::create(static function () use ($connection, $group, &$pids): void {
                    $pids[] = self::backendPid($connection);
                    $connection->executeQuery('SELECT pg_sleep(0.2)');
                    $group->done();
                });
            }

            $group->wait();

            self::assertCount(5, array_unique($pids), 'Две корутины получили одно соединение');
            self::assertLessThanOrEqual(5, self::pool($registry)->stats()->createdTotal);
        });
    }

    public function testAChildCoroutineIsOutsideTheParentTransaction(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 2]);
            $table = self::table('items');

            $connection->beginTransaction();
            $connection->executeStatement("INSERT INTO {$table} (marker) VALUES ('parent')");

            $seenByChild = null;
            $group = new WaitGroup();
            $group->add();
            Coroutine::create(static function () use ($connection, $table, $group, &$seenByChild): void {
                $seenByChild = (int)$connection->fetchOne("SELECT count(*) FROM {$table} WHERE marker = 'parent'");
                $group->done();
            });
            $group->wait();

            self::assertSame(0, $seenByChild, 'Дочерняя корутина видит незакоммиченную строку — она в транзакции родителя');
            self::assertTrue($connection->isTransactionActive(), 'Транзакция родителя не пострадала');
            $connection->rollBack();
        });
    }

    public function testInsertAndLastInsertIdStayOnOneSessionUnderConcurrency(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 4]);
            $table = self::table('items');
            $group = new WaitGroup();
            $mismatches = 0;

            for ($i = 0; $i < 20; $i++) {
                $group->add();
                Coroutine::create(static function () use ($connection, $table, $group, $i, &$mismatches): void {
                    $marker = 'lastval-' . $i;
                    $connection->executeStatement("INSERT INTO {$table} (marker) VALUES (?)", [$marker]);
                    $id = (int)$connection->lastInsertId();
                    $stored = (int)$connection->fetchOne("SELECT id FROM {$table} WHERE marker = ?", [$marker]);

                    if ($id !== $stored) {
                        $mismatches++;
                    }

                    $connection->close();
                    $group->done();
                });
            }

            $group->wait();

            self::assertSame(0, $mismatches, 'lastInsertId() вернул lastval чужой сессии');
        });
    }

    public function testUncommittedWorkIsRolledBackWhenTheCoroutineEnds(): void
    {
        $dispatcher = new RecordingDispatcher();

        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 1]);
            $table = self::table('items');
            $pid = 0;
            $group = new WaitGroup();
            $group->add();

            Coroutine::create(static function () use ($connection, $table, $group, &$pid): void {
                $connection->beginTransaction();
                $connection->executeStatement("INSERT INTO {$table} (marker) VALUES ('abandoned')");
                $pid = self::backendPid($connection);
                // Без commit и без rollBack: обработчик «упал».
                $group->done();
            });

            $group->wait();

            self::assertSame(0, (int)$connection->fetchOne("SELECT count(*) FROM {$table} WHERE marker = 'abandoned'"));
            self::assertSame($pid, self::backendPid($connection), 'То же соединение — теперь у главной корутины');
            self::assertFalse($connection->isTransactionActive());
            self::assertSame(1, self::pool($registry)->stats()->rollbacksOnReleaseTotal);
            $connection->close();
            self::assertSame('idle', self::db()->backendState($pid), 'После возврата бэкенд не должен быть idle in transaction');
        }, $dispatcher);

        self::assertCount(1, $dispatcher->of(RolledBackOnRelease::class));
        self::assertTrue($dispatcher->of(RolledBackOnRelease::class)[0]->viaDefer);
    }

    public function testARawBeginThroughExecIsRolledBackOnRelease(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 1]);
            $table = self::table('items');

            $connection->executeStatement('BEGIN');
            $connection->executeStatement("INSERT INTO {$table} (marker) VALUES ('raw')");
            self::assertFalse($connection->isTransactionActive(), 'DBAL про сырой BEGIN не знает');
            $connection->close();

            self::assertSame(0, (int)$connection->fetchOne("SELECT count(*) FROM {$table} WHERE marker = 'raw'"));
            self::assertSame(1, self::pool($registry)->stats()->rollbacksOnReleaseTotal, 'PDO::inTransaction() видит серверное состояние');
        });
    }

    public function testSessionStateDoesNotLeakBetweenLeasesWithDiscard(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 1]);
            $schema = self::db()->schema;

            $connection->executeStatement("SET search_path TO \"{$schema}\"");
            $connection->executeStatement('SELECT pg_advisory_lock(4242)');
            $connection->executeStatement('CREATE TEMP TABLE leaked_tmp (a int)');
            $pid = self::backendPid($connection);
            $connection->close();

            self::assertSame($pid, self::backendPid($connection), 'Проверяем именно то же соединение');
            self::assertStringNotContainsString($schema, (string)$connection->fetchOne('SHOW search_path'));
            self::assertSame(
                0,
                (int)$connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"),
                'Advisory lock пережил возврат в пул',
            );
            self::assertNull($connection->fetchOne("SELECT to_regclass('pg_temp.leaked_tmp')"), 'Temp-таблица пережила возврат в пул');
        });
    }

    public function testSessionStateDoesLeakWithRollbackOnly(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 1, 'reset_on_release' => 'rollback_only']);
            $schema = self::db()->schema;

            $connection->executeStatement("SET search_path TO \"{$schema}\"");
            $pid = self::backendPid($connection);
            $connection->close();

            self::assertSame($pid, self::backendPid($connection));
            self::assertStringContainsString(
                $schema,
                (string)$connection->fetchOne('SHOW search_path'),
                'rollback_only по определению не сбрасывает сессию — это цена за экономию round-trip',
            );
            $connection->executeStatement('RESET search_path');
        });
    }

    public function testAStatementExecutedFromAnotherCoroutineIsRejectedAndTheTransactionSurvives(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 2]);
            $table = self::table('items');

            $connection->beginTransaction();
            $statement = $connection->prepare("INSERT INTO {$table} (marker) VALUES ('foreign')");
            $violation = null;
            $group = new WaitGroup();
            $group->add();

            Coroutine::create(static function () use ($statement, $group, &$violation): void {
                try {
                    $statement->executeStatement();
                } catch (LeaseViolationException $e) {
                    $violation = $e;
                }

                $group->done();
            });

            $group->wait();

            self::assertInstanceOf(LeaseViolationException::class, $violation);
            self::assertTrue($connection->isTransactionActive());
            $statement->executeStatement();
            $connection->commit();
            self::assertSame(1, (int)$connection->fetchOne("SELECT count(*) FROM {$table} WHERE marker = 'foreign'"));
        });
    }

    public function testTwoDsnsGetTwoPoolsAndOneDsnSharesOne(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $a = self::connection($registry, [], applicationName: 'pool-a');
            $b = self::connection($registry, [], applicationName: 'pool-a');
            $c = self::connection($registry, [], applicationName: 'pool-c');

            $a->fetchOne('SELECT 1');
            $b->fetchOne('SELECT 1');
            $c->fetchOne('SELECT 1');

            self::assertSame(2, $registry->count());
            self::assertSame($a->getPoolKey()->hash, $b->getPoolKey()->hash);
            self::assertSame(1, self::backendCount($a), 'Одно DBAL-соединение, один lease');
        });
    }

    private static function backendCount(\SwooleDoctrinePool\DBAL\CoroutineSafeConnection $connection): int
    {
        return (int)$connection->fetchOne(
            "SELECT count(*) FROM pg_stat_activity WHERE application_name = 'pool-a' AND pid = pg_backend_pid()",
        );
    }
}
