<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Pool;

use Doctrine\DBAL\Driver\PDO\Exception;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SwooleDoctrinePool\Pool\LostConnectionClassifier;

#[CoversClass(LostConnectionClassifier::class)]
final class LostConnectionClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'connection failure 08006' => ['08006', 'SQLSTATE[08006] connection failure', true];
        yield 'unable to connect 08001' => ['08001', 'could not connect', true];
        yield 'admin shutdown 57P01' => ['57P01', 'terminating connection due to administrator command', true];
        yield 'crash shutdown 57P02' => ['57P02', 'the database system is shutting down', true];
        yield 'message only' => ['HY000', 'server closed the connection unexpectedly', true];
        yield 'unique violation' => ['23505', 'duplicate key value violates unique constraint', false];
        yield 'deadlock' => ['40P01', 'deadlock detected', false];
        yield 'syntax error' => ['42601', 'syntax error at or near', false];
        yield 'query canceled' => ['57014', 'canceling statement due to statement timeout', false];
    }

    #[DataProvider('cases')]
    public function testItRecognisesLostConnectionsBySqlStateOrMessage(string $sqlState, string $message, bool $lost): void
    {
        $pdoException = new PDOException($message);
        $pdoException->errorInfo = [$sqlState, 7, $message];

        $classifier = new LostConnectionClassifier();

        self::assertSame($lost, $classifier->isLost($pdoException));
        self::assertSame($lost, $classifier->isLost(Exception::new($pdoException)), 'Через DBAL-обёртку исключения');
    }

    public function testItLooksThroughThePreviousChain(): void
    {
        $inner = new PDOException('server closed the connection unexpectedly');
        $outer = new RuntimeException('wrapped', 0, $inner);

        self::assertTrue((new LostConnectionClassifier())->isLost($outer));
    }

    public function testAnUnrelatedExceptionIsNotLost(): void
    {
        self::assertFalse((new LostConnectionClassifier())->isLost(new RuntimeException('nope')));
    }
}
