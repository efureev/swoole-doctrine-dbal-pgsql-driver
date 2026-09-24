<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\DBAL;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Doctrine\DBAL\Exception\CommitFailedRollbackOnly;
use Doctrine\DBAL\TransactionIsolationLevel;
use PDOException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Pool\CountingSlots;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Pool\Slots;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeClock;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeCoroutineApi;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeInnerDriver;
use SwooleDoctrinePool\Tests\Unit\Fake\FakePdo;

#[CoversClass(CoroutineSafeConnection::class)]
final class CoroutineSafeConnectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array PARAMS = [
        'host' => 'db',
        'dbname' => 'app',
        'user' => 'app',
        'password' => 'secret',
        'serverVersion' => '17.2',
        'wrapperClass' => CoroutineSafeConnection::class,
        'driverOptions' => ['pool' => ['size' => 4]],
    ];

    private FakeCoroutineApi $api;
    private FakeInnerDriver $inner;
    private PoolRegistry $registry;

    #[Override]
    protected function setUp(): void
    {
        $this->api = new FakeCoroutineApi(cid: 1);
        $this->inner = new FakeInnerDriver();
        $this->registry = new PoolRegistry(
            api: $this->api,
            clock: new FakeClock(),
            slotsFactory: static fn(int $size): Slots => new CountingSlots($size),
        );
    }

    public function testTransactionNestingLevelIsIsolatedPerCoroutine(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        self::assertSame(1, $connection->getTransactionNestingLevel());

        $this->api->switchTo(2);
        self::assertFalse($connection->isTransactionActive(), 'Чужая транзакция не видна');
        self::assertSame(0, $connection->getTransactionNestingLevel());

        $connection->beginTransaction();
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'Уровень 1, а не 2: у корутины свой счётчик');
        self::assertCount(2, $this->inner->connections);
        self::assertSame(['beginTransaction'], $this->inner->connections[1]->pdo->log, 'BEGIN, а не SAVEPOINT');

        $this->api->switchTo(1);
        self::assertSame(1, $connection->getTransactionNestingLevel());
    }

    public function testNestedTransactionsUseSavepointsOnTheSameConnection(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->rollBack();
        self::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();

        self::assertSame(
            ['beginTransaction', 'exec:SAVEPOINT DOCTRINE_2', 'exec:ROLLBACK TO SAVEPOINT DOCTRINE_2', 'commit'],
            $this->pdo()->log,
        );
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertCount(1, $this->inner->connections);
    }

    public function testWithAutoCommitOffATransactionIsOpenedOnFirstUseAndReopenedAfterCommit(): void
    {
        $config = new Configuration();
        $config->setAutoCommit(false);
        $connection = $this->connection($config);

        $connection->executeStatement('UPDATE t SET x = 1');
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'Транзакция открыта автоматически, ровно одна');

        $connection->beginTransaction();
        self::assertSame(2, $connection->getTransactionNestingLevel());
        $connection->commit();
        $connection->commit();

        self::assertSame(1, $connection->getTransactionNestingLevel(), 'После commit без autocommit транзакция открыта снова');
        self::assertSame(
            ['beginTransaction', 'exec:UPDATE t SET x = 1', 'exec:SAVEPOINT DOCTRINE_2', 'exec:RELEASE SAVEPOINT DOCTRINE_2', 'commit', 'beginTransaction'],
            $this->pdo()->log,
        );
    }

    public function testCloseReleasesOnlyTheCurrentCoroutineLease(): void
    {
        $connection = $this->connection();
        $connection->executeQuery('SELECT 1');
        $this->api->switchTo(2);
        $connection->executeQuery('SELECT 2');

        $this->api->switchTo(1);
        $connection->close();

        self::assertFalse($connection->isConnected());
        self::assertSame(1, $this->pool()->stats()->inUse, 'Lease второй корутины не тронут');
        $this->api->switchTo(2);
        self::assertTrue($connection->isConnected());
    }

    public function testACoroutineEndingMidTransactionIsRolledBackAndLeavesNoState(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t VALUES (1)');
        $pdo = $this->pdo();

        $this->api->finishCoroutine(1);

        self::assertContains('rollBack', $pdo->log, 'Незакоммиченная работа откачена, а не отдана следующему');
        self::assertSame(1, $this->pool()->stats()->rollbacksOnReleaseTotal);
        self::assertSame(0, $connection->getTransactionNestingLevel(), 'Новая корутина с тем же cid начинает с нуля');
        self::assertFalse($connection->isConnected());
    }

    public function testALostConnectionDuringAQueryEndsTheLeaseAndTheTransactionState(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $this->pdo()->failOn['exec'] = $this->pdoException('08006', 'server closed the connection unexpectedly');

        try {
            $connection->executeStatement('UPDATE t SET x = 1');
            self::fail('Ожидался ConnectionLost');
        } catch (ConnectionLost) {
        }

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertFalse($connection->isConnected());
        self::assertSame(1, $this->pool()->stats()->closedTotal, 'Мёртвое соединение уничтожено, не возвращено');

        $connection->executeQuery('SELECT 1');
        self::assertCount(2, $this->inner->connections, 'Следующий запрос идёт на свежем соединении');
    }

    /** @psalm-suppress UnusedVariable, RedundantCondition psalm считает, что never-closure не даёт выйти из try */
    public function testTransactionalRollsBackWhenTheCallbackThrows(): void
    {
        $connection = $this->connection();

        $escaped = false;

        try {
            $connection->transactional(static function (): void {
                throw new RuntimeException('business error');
            });
        } catch (RuntimeException) {
            $escaped = true;
        }

        self::assertTrue($escaped, 'Исключение должно пройти наружу');
        self::assertSame(['beginTransaction', 'rollBack'], $this->pdo()->log);
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testLastInsertIdRunsOnTheSameConnectionAsTheInsert(): void
    {
        $connection = $this->connection();

        $connection->executeStatement('INSERT INTO t VALUES (1)');
        self::assertSame('42', $connection->lastInsertId());
        self::assertCount(1, $this->inner->connections);
        self::assertSame(['exec:INSERT INTO t VALUES (1)', 'lastInsertId'], $this->pdo()->log);
    }

    public function testRollbackOnlyIsTrackedPerCoroutine(): void
    {
        $connection = $this->connection();

        try {
            $connection->setRollbackOnly();
            self::fail('Без транзакции setRollbackOnly невозможен');
        } catch (NoActiveTransaction) {
        }

        $connection->beginTransaction();
        $connection->setRollbackOnly();
        self::assertTrue($connection->isRollbackOnly());

        $this->api->switchTo(2);
        $connection->beginTransaction();
        self::assertFalse($connection->isRollbackOnly());
        $this->api->switchTo(1);

        $this->expectException(CommitFailedRollbackOnly::class);
        $connection->commit();
    }

    public function testDirectModeOutsideCoroutinesTracksTransactionsInTheWrapper(): void
    {
        $this->api->leaveCoroutines();
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t VALUES (1)');
        self::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(['beginTransaction', 'exec:INSERT INTO t VALUES (1)', 'commit'], $this->pdo()->log);

        $connection->beginTransaction();
        $connection->close();
        self::assertFalse($connection->isConnected());
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertContains('rollBack', $this->pdo()->log, 'close() с открытой транзакцией откатывает её');
    }

    public function testIsolationLevelLivesWithTheLease(): void
    {
        $connection = $this->connection();

        $connection->setTransactionIsolation(TransactionIsolationLevel::SERIALIZABLE);
        self::assertSame(TransactionIsolationLevel::SERIALIZABLE, $connection->getTransactionIsolation());

        $this->api->switchTo(2);
        self::assertSame(TransactionIsolationLevel::READ_COMMITTED, $connection->getTransactionIsolation());
    }

    public function testPoolKeyIsExposedForDiagnostics(): void
    {
        self::assertSame('app@db:5432/app', $this->connection()->getPoolKey()->label);
    }

    private function connection(?Configuration $config = null): CoroutineSafeConnection
    {
        return new CoroutineSafeConnection(self::PARAMS, new Driver($this->inner, $this->registry), $config, $this->api);
    }

    private function pdo(): FakePdo
    {
        return $this->inner->lastPdo();
    }

    private function pool(): \SwooleDoctrinePool\Pool\Pool
    {
        $pools = $this->registry->all();

        return $pools[array_key_first($pools)];
    }

    private function pdoException(string $sqlState, string $message): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = [$sqlState, 7, $message];

        return $e;
    }
}
