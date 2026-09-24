<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Driver;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use PDOException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Driver\LeaseAwareResult;
use SwooleDoctrinePool\Driver\LeaseAwareStatement;
use SwooleDoctrinePool\Driver\VirtualConnection;
use SwooleDoctrinePool\Event\ConnectionClosed;
use SwooleDoctrinePool\Exception\InvalidConfigurationException;
use SwooleDoctrinePool\Exception\LeaseViolationException;
use SwooleDoctrinePool\Pool\CloseReason;
use SwooleDoctrinePool\Pool\CountingSlots;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Pool\Slots;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeClock;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeCoroutineApi;
use SwooleDoctrinePool\Tests\Unit\Fake\FakeInnerDriver;
use SwooleDoctrinePool\Tests\Unit\Fake\RecordingDispatcher;

#[CoversClass(VirtualConnection::class)]
#[CoversClass(LeaseAwareStatement::class)]
#[CoversClass(LeaseAwareResult::class)]
#[CoversClass(Driver::class)]
final class VirtualConnectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array PARAMS = [
        'host' => 'db',
        'dbname' => 'app',
        'user' => 'app',
        'password' => 'secret',
        'wrapperClass' => CoroutineSafeConnection::class,
        'driverOptions' => ['pool' => ['size' => 3]],
    ];

    private FakeCoroutineApi $api;
    private FakeInnerDriver $inner;
    private PoolRegistry $registry;
    private RecordingDispatcher $dispatcher;
    private Driver $driver;

    #[Override]
    protected function setUp(): void
    {
        $this->api = new FakeCoroutineApi(cid: 1);
        $this->inner = new FakeInnerDriver();
        $this->dispatcher = new RecordingDispatcher();
        $this->registry = new PoolRegistry(
            dispatcher: $this->dispatcher,
            api: $this->api,
            clock: new FakeClock(),
            slotsFactory: static fn(int $size): Slots => new CountingSlots($size),
        );
        $this->driver = new Driver($this->inner, $this->registry);
    }

    public function testConnectIsCheapAndThePoolAppearsOnTheFirstQuery(): void
    {
        $connection = $this->driver->connect(self::PARAMS);

        self::assertSame(0, $this->registry->count());
        self::assertCount(0, $this->inner->connections);

        $connection->query('SELECT 1');

        self::assertSame(1, $this->registry->count());
        self::assertCount(1, $this->inner->connections);
    }

    public function testTheSecondQueryInTheSameCoroutineReusesTheLease(): void
    {
        $connection = $this->driver->connect(self::PARAMS);

        $connection->query('SELECT 1');
        $connection->exec('UPDATE t SET x = 1');

        self::assertCount(1, $this->inner->connections);
        self::assertSame(['query:SELECT 1', 'exec:UPDATE t SET x = 1'], $this->inner->lastPdo()->log);
        self::assertSame(1, $this->pool()->stats()->inUse);
    }

    public function testAnotherCoroutineGetsAnotherPhysicalConnection(): void
    {
        $connection = $this->driver->connect(self::PARAMS);
        $connection->query('SELECT 1');

        $this->api->switchTo(2);
        $connection->query('SELECT 2');

        self::assertCount(2, $this->inner->connections);
        self::assertSame(2, $this->pool()->stats()->inUse);
    }

    public function testAStatementCannotBeExecutedFromAnotherCoroutine(): void
    {
        $statement = $this->driver->connect(self::PARAMS)->prepare('SELECT ?');
        $this->api->switchTo(2);

        try {
            $statement->execute();
            self::fail('Statement чужой корутины выполнился');
        } catch (LeaseViolationException $e) {
            self::assertStringContainsString('корутине 1', $e->getMessage());
            self::assertStringContainsString('корутины 2', $e->getMessage());
        }

        $this->api->switchTo(1);
        $statement->execute();
        self::assertSame(['query:SELECT ?'], $this->inner->lastPdo()->log, 'Из корутины-владельца — выполняется');
    }

    public function testAStatementCannotBeExecutedAfterItsLeaseWasReleased(): void
    {
        $statement = $this->driver->connect(self::PARAMS)->prepare('SELECT 1');
        $this->registry->releaseCurrentCoroutine();

        $this->expectException(LeaseViolationException::class);
        $statement->execute();
    }

    public function testAResultCanBeReadFromAnotherCoroutineButNotAfterRelease(): void
    {
        $result = $this->driver->connect(self::PARAMS)->query('SELECT 1');

        $this->api->switchTo(2);
        self::assertSame([1], $result->fetchNumeric(), 'Буферизованный результат читается из любой корутины');

        $this->api->switchTo(1);
        $this->registry->releaseCurrentCoroutine();

        $this->expectException(LeaseViolationException::class);
        $result->fetchAllNumeric();
    }

    public function testALostConnectionMarksTheLeaseBrokenSoReleaseDestroysIt(): void
    {
        $connection = $this->driver->connect(self::PARAMS);
        $connection->query('SELECT 1');
        $this->inner->lastPdo()->failOn['query'] = $this->pdoException('08006', 'server closed the connection unexpectedly');

        try {
            $connection->query('SELECT 2');
            self::fail('Ожидалась ошибка');
        } catch (PdoDriverException) {
        }

        $this->registry->releaseCurrentCoroutine();

        self::assertSame(0, $this->pool()->stats()->idle);
        self::assertSame(CloseReason::Broken, $this->dispatcher->of(ConnectionClosed::class)[0]->reason);
    }

    public function testAConstraintViolationLeavesTheConnectionReusable(): void
    {
        $connection = $this->driver->connect(self::PARAMS);
        $connection->query('SELECT 1');
        $this->inner->lastPdo()->failOn['query'] = $this->pdoException('23505', 'duplicate key');

        try {
            $connection->query('INSERT');
            self::fail('Ожидалась ошибка');
        } catch (PdoDriverException) {
        }

        $this->registry->releaseCurrentCoroutine();

        self::assertSame(1, $this->pool()->stats()->idle);
    }

    public function testOutsideACoroutineOneDirectConnectionIsUsedWithoutAPool(): void
    {
        $this->api->leaveCoroutines();
        $connection = $this->driver->connect(self::PARAMS);

        $connection->query('SELECT 1');
        $connection->query('SELECT 2');

        self::assertSame(0, $this->registry->count(), 'Пул вне корутины не создаётся');
        self::assertCount(1, $this->inner->connections);
        self::assertSame(-1, $connection->currentLease()?->ownerCid);
        self::assertCount(0, $this->api->timers);
    }

    public function testDroppingTheDirectConnectionRollsBackAndClosesIt(): void
    {
        $this->api->leaveCoroutines();
        $connection = $this->driver->connect(self::PARAMS);
        $connection->beginTransaction();
        $pdo = $this->inner->lastPdo();

        unset($connection);

        self::assertSame(['beginTransaction', 'rollBack'], $pdo->log);
    }

    public function testADirectConnectionThatBrokeIsReopenedOnNextUse(): void
    {
        $this->api->leaveCoroutines();
        $connection = $this->driver->connect(self::PARAMS);
        $connection->query('SELECT 1');
        $this->inner->lastPdo()->failOn['query'] = $this->pdoException('08006', 'connection failure');

        try {
            $connection->query('SELECT 2');
            self::fail('Ожидалась ошибка');
        } catch (PdoDriverException) {
        }

        $connection->query('SELECT 3');

        self::assertCount(2, $this->inner->connections);
    }

    public function testTheWrapperClassIsMandatory(): void
    {
        $params = self::PARAMS;
        unset($params['wrapperClass']);

        $this->expectException(InvalidConfigurationException::class);
        $this->driver->connect($params);
    }

    public function testAForeignWrapperClassIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->driver->connect(['wrapperClass' => \Doctrine\DBAL\Connection::class] + self::PARAMS);
    }

    public function testADisabledPdoHookDoesNotStopTheDriver(): void
    {
        $this->api->hookEnabled = false;

        self::assertSame([1], $this->driver->connect(self::PARAMS)->query('SELECT 1')->fetchNumeric());
    }

    public function testPoolOptionsAndWrapperClassNeverReachTheInnerDriver(): void
    {
        $this->driver->connect([
            'driverOptions' => ['pool' => ['size' => 2], \PDO::ATTR_TIMEOUT => 7],
        ] + self::PARAMS)->query('SELECT 1');

        $seen = $this->inner->paramsSeen[0];
        self::assertArrayNotHasKey('wrapperClass', $seen);
        self::assertArrayNotHasKey('driverClass', $seen);
        self::assertSame([\PDO::ATTR_TIMEOUT => 7], $seen['driverOptions']);
    }

    public function testAClosedPoolIsReplacedOnTheNextQueryOfTheSameConnection(): void
    {
        $connection = $this->driver->connect(self::PARAMS);
        $connection->query('SELECT 1');
        // Как console.terminate: сначала отдать lease текущей корутины, затем закрыть пулы.
        $this->registry->releaseCurrentCoroutine();
        $this->registry->closeAll(0.0);

        self::assertSame([1], $connection->query('SELECT 2')->fetchNumeric(), 'После closeAll соединение работает через новый пул');
        self::assertSame(1, $this->registry->count());
        self::assertCount(2, $this->inner->connections);
    }

    public function testTheServerVersionComesFromThePoolWithoutANewLease(): void
    {
        $connection = $this->driver->connect(self::PARAMS);
        $connection->query('SELECT 1');
        $this->registry->releaseCurrentCoroutine();

        self::assertSame('17.2', $connection->getServerVersion());
        self::assertSame(0, $this->pool()->stats()->inUse, 'Версия отдана из кэша пула, lease не брался');
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
