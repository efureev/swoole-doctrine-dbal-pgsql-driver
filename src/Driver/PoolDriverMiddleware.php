<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Driver;

use Doctrine\DBAL\Connection\StaticServerVersionProvider;
use Doctrine\DBAL\Driver as DoctrineDriver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as StockPgsqlDriver;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Override;
use SwooleDoctrinePool\Exception\InvalidConfigurationException;
use SwooleDoctrinePool\Pool\PoolRegistry;

/**
 * Переводит любой PostgreSQL-драйвер на пул и подставляет общий PoolRegistry. Должен быть самым
 * внутренним middleware (в DoctrineBundle — наибольший priority): пул поверх пула не работает.
 */
class PoolDriverMiddleware implements Middleware
{
    public function __construct(protected readonly PoolRegistry $registry)
    {
    }

    #[Override]
    public function wrap(DoctrineDriver $driver): DoctrineDriver
    {
        if ($driver instanceof Driver) {
            return $driver->withRegistry($this->registry);
        }

        // Штатный драйвер DBAL на PHP >= 8.4 требует класс Pdo\Pgsql, которого во встроенном в Swoole pdo_pgsql нет.
        if ($driver instanceof StockPgsqlDriver) {
            return new Driver(new PgsqlDriver(), $this->registry);
        }

        // Через getDatabasePlatform() проверка проходит сквозь чужие декораторы, instanceof — нет.
        $platform = $driver->getDatabasePlatform(new StaticServerVersionProvider('17.0'));

        if (!$platform instanceof PostgreSQLPlatform) {
            throw InvalidConfigurationException::notAPostgresDriver($driver::class);
        }

        return new Driver($driver, $this->registry);
    }
}
