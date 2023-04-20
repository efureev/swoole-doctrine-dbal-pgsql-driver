<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\ConnectionException;

use function implode;
use function sprintf;

/** @psalm-suppress UndefinedClass, DeprecatedInterface, MissingDependency */
final class Driver extends AbstractPostgreSQLDriver
{
    private ConnectionPoolFactoryInterface $poolFactory;
    private ConnectionPoolKeeper $poolKeeper;

    public function setPoolKeeper(ConnectionPoolKeeper $keeper): void
    {
        $this->poolKeeper = $keeper;
    }

    public function setPoolFactory(ConnectionPoolFactoryInterface $factory): void
    {
        $this->poolFactory = $factory;
    }

    private function makePool(ConnectionPoolConfig $params): ConnectionPoolInterface
    {
        return $this->poolFactory->factory($params);
    }

    private function buildPool(ConnectionPoolConfig $params): ConnectionPoolInterface
    {
        if ($this->poolKeeper->isEmpty()) {
            $this->poolKeeper->set($this->makePool($params));
        }
        return $this->poolKeeper->get();
    }

    /**
     * {@inheritdoc}
     *
     * @param string|null $username
     * @param string|null $password
     */
    public function connect(
        array $params,
        $username = null,
        $password = null,
        array $driverOptions = []
    ): ConnectionDirect {
        $config = new ConnectionPoolConfig($params);

        if (!$config->useConnectionPool) {
            return new ConnectionDirect(static fn(): PostgreSQL => self::createConnection(self::generateDSN($config)));
        }

        $pool = $this->buildPool($config);

        return new Connection(
            $pool,
            $config->retryDelay,
            $config->retryMaxAttempts,
            $config->connectionDelay
        );
    }

    /**
     * Create new connection for pool
     *
     * @throws ConnectionException
     */
    public static function createConnection(string $dsn): PostgreSQL
    {
        $pgsql = new PostgreSQL();
        if (!$pgsql->connect($dsn)) {
            throw new ConnectionException(sprintf('Failed to connect: %s', ($pgsql->error ?? 'Unknown')));
        }

        return $pgsql;
    }

    /**
     * @deprecated
     */
    public function getName(): string
    {
        return 'swoole_pgsql';
    }

    /**
     * Generate DSN using passed params
     */
    public static function generateDSN(ConnectionPoolConfig $params): string
    {
        /** @var ?string $url */
        $url = $params->get('url');
        if ($url !== null) {
            return $url;
        }

        return implode(';', [
            'host=' . $params->get('host', '127.0.0.1'),
            'port=' . $params->get('port', '5432'),
            'dbname=' . $params->get('dbname', 'postgres'),
            'user=' . $params->get('user', 'postgres'),
            'password=' . $params->get('password', 'postgres'),
        ]);
    }
}
