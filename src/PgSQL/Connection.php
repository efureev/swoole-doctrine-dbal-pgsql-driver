<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Doctrine\DBAL\Exception as DBALException;
use Swoole\Coroutine as Co;
use Swoole\Coroutine\Context;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\ConnectionException;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\DriverException;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\PingException;
use Throwable;
use function defer;
use function time;

final class Connection extends ConnectionDirect
{
    public function __construct(
        private ConnectionPoolInterface $pool,
        private int                     $retryDelay,
        private int                     $maxAttempts,
        private int                     $connectionDelay,
    )
    {
    }

    public function getNativeConnection(): PostgreSQL
    {
        $context = $this->getContext();

        /** @psalm-suppress MixedArrayAccess, MixedAssignment */
        [$connection] = $context[self::class] ?? [null, null];

        /** @psalm-suppress RedundantCondition */
        if (!$connection instanceof PostgreSQL) {
            $lastException = null;
            for ($i = 0; $i < $this->maxAttempts; $i++) {
                try {
                    /**
                     * @psalm-suppress UnnecessaryVarAnnotation
                     * @psalm-var PostgreSQL $connection
                     * @psalm-var ConnectionStats $stats
                     */
                    [$connection, $stats] = $this->pool->get($this->connectionDelay);
//                    [$connection, $stats] = match (true) {
//                        $this->connectionFactory === null => $this->pool->get($this->connectionDelay),
//                        default => [($this->connectionFactory)(), new ConnectionStats(0, 0)]
//                    };

                    if (!$connection instanceof PostgreSQL) {
                        throw new DriverException('No connect available in pull');
                    }
                    if (!$stats instanceof ConnectionStats) {
                        throw new DriverException('Provided connect is corrupted');
                    }
                    $this->ping($connection);

                    $context[self::class] = [$connection, $stats];

                    /** @psalm-suppress UnusedFunctionCall */
                    defer($this->onDefer(...));

                    break;
                } catch (PingException) {
                    $errCode = (string)$connection->errCode;
                    $lastException = new ConnectionException(
                        "Connection ping failed. Trying reconnect (attempt $i). Reason: $errCode"
                    );
                    $connection = null;

                    usleep($this->retryDelay);  // Sleep mсs after failure
                } catch (Throwable $e) {
                    $errCode = '';
                    if ($connection instanceof PostgreSQL) {
                        $errCode = (int)$connection->errCode;
                        $connection = null;
                    }
                    $lastException = $e instanceof DBALException
                        ? $e
                        : new ConnectionException($e->getMessage(), (string)$errCode, '', (int)$e->getCode(), $e);

                    // usleep($this->retryDelay);
                    break;
                }
            }

            if (!$connection instanceof PostgreSQL) {
                $lastException instanceof Throwable
                    ? throw $lastException
                    : throw new ConnectionException('Connection could not be initiated');
            }
        }

        /** @psalm-suppress MixedArrayAccess, MixedAssignment */
        [$connection] = $context[self::class] ?? [null];

        /** @psalm-suppress RedundantCondition */
        if (!$connection instanceof PostgreSQL) {
            throw new ConnectionException('Connection in context storage is corrupted');
        }

        return $connection;
    }

    /** @psalm-suppress UnusedVariable, MixedArrayAccess, MixedAssignment */
    public function connectionStats(): ?ConnectionStats
    {
        [, $stats] = $this->getContext()[self::class] ?? [null, null];

        return $stats;
    }

    /**
     * @psalm-suppress MixedReturnTypeCoercion
     * @return Context
     * @throws ConnectionException
     */
    private function getContext(): Context
    {
        $context = Co::getContext(Co::getCid());

        if ($context === null) {
            throw new ConnectionException('Connection Co::Context unavailable');
        }

        return $context;
    }

    /**
     * @throws ConnectionException
     */
    private function onDefer(): void
    {
//        if ($this->connectionFactory !== null) {
//            return;
//        }

        $context = $this->getContext();
        /** @psalm-suppress MixedArrayAccess, MixedAssignment */
        [$connection, $stats] = $context[self::class] ?? [null, null];

        /** @psalm-suppress RedundantCondition */
        if (!$connection instanceof PostgreSQL) {
            return;
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($stats instanceof ConnectionStats) {
            $stats->lastInteraction = time();
        }

        $this->pool->put($connection);
        unset($context[self::class]);
    }
}
