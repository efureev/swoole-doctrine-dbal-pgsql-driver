<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Driver;

use Doctrine\DBAL\Driver\API\PostgreSQL\ExceptionConverter;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Connection\StaticServerVersionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Driver\PgsqlDriver;
use SwooleDoctrinePool\Exception\InvalidConfigurationException;

#[CoversClass(PgsqlDriver::class)]
final class PgsqlDriverTest extends TestCase
{
    public function testTheDsnMirrorsDoctrineParameters(): void
    {
        $dsn = PgsqlDriver::dsn([
            'host' => 'db',
            'port' => 5433,
            'dbname' => 'app',
            'user' => 'app',
            'password' => 'secret',
            'sslmode' => 'require',
            'application_name' => 'svc',
            'charset' => 'utf8',
            'driverOptions' => ['pool' => []],
        ]);

        self::assertSame('pgsql:host=db;port=5433;dbname=app;sslmode=require;application_name=svc;', $dsn);
        self::assertStringNotContainsString('secret', $dsn);
    }

    public function testEmptyValuesAreSkipped(): void
    {
        self::assertSame('pgsql:dbname=app;', PgsqlDriver::dsn(['host' => '', 'port' => null, 'dbname' => 'app']));
    }

    public function testPersistentConnectionsAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new PgsqlDriver())->connect(['persistent' => true, 'host' => 'db']);
    }

    public function testItIsAPostgresDriverForDbal(): void
    {
        $driver = new PgsqlDriver();

        self::assertInstanceOf(PostgreSQLPlatform::class, $driver->getDatabasePlatform(new StaticServerVersionProvider('17.0')));
        self::assertInstanceOf(ExceptionConverter::class, $driver->getExceptionConverter());
    }
}
