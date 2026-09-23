<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Driver;

use Doctrine\DBAL\Driver\API\PostgreSQL\ExceptionConverter;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PDOException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Driver\PoolExceptionConverter;
use SwooleDoctrinePool\Exception\AcquireTimeoutException;
use SwooleDoctrinePool\Exception\ConnectionLostException;
use SwooleDoctrinePool\Exception\PoolClosedException;
use SwooleDoctrinePool\Exception\PoolExhaustedException;
use SwooleDoctrinePool\Pool\LostConnectionClassifier;

#[CoversClass(PoolExceptionConverter::class)]
final class PoolExceptionConverterTest extends TestCase
{
    private PoolExceptionConverter $converter;

    #[Override]
    protected function setUp(): void
    {
        $this->converter = new PoolExceptionConverter(new ExceptionConverter(), new LostConnectionClassifier());
    }

    public function testAcquireTimeoutBecomesPoolExhaustedWhichIsAConnectionException(): void
    {
        $converted = $this->converter->convert(AcquireTimeoutException::afterWaiting('p', 5.0, 10, 2), null);

        self::assertInstanceOf(PoolExhaustedException::class, $converted);
        self::assertInstanceOf(ConnectionException::class, $converted);
        self::assertNotInstanceOf(ConnectionLost::class, $converted, 'Исчерпание пула — не потеря соединения: lease нечего закрывать');
    }

    public function testPoolClosedBecomesPoolExhausted(): void
    {
        self::assertInstanceOf(PoolExhaustedException::class, $this->converter->convert(PoolClosedException::forPool('p'), null));
    }

    public function testOurConnectionLostBecomesDbalConnectionLost(): void
    {
        $converted = $this->converter->convert(ConnectionLostException::brokenInTransaction('p', 1), null);

        self::assertInstanceOf(ConnectionLost::class, $converted);
    }

    public function testSqlState08006IsUpgradedToConnectionLost(): void
    {
        self::assertInstanceOf(ConnectionLost::class, $this->converter->convert($this->pdo('08006', 'connection failure'), null));
    }

    public function testAdminShutdownIsUpgradedToConnectionLost(): void
    {
        self::assertInstanceOf(ConnectionLost::class, $this->converter->convert($this->pdo('57P01', 'terminating'), null));
    }

    public function testOrdinaryErrorsKeepTheirSpecificDbalClass(): void
    {
        self::assertInstanceOf(
            UniqueConstraintViolationException::class,
            $this->converter->convert($this->pdo('23505', 'duplicate key'), null),
        );
        self::assertInstanceOf(
            DeadlockException::class,
            $this->converter->convert($this->pdo('40P01', 'deadlock detected'), null),
        );
    }

    private function pdo(string $sqlState, string $message): Exception
    {
        $e = new PDOException($message);
        $e->errorInfo = [$sqlState, 7, $message];

        return Exception::new($e);
    }
}
