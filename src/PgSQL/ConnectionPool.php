<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\PgSQL;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Events\PoolClosed;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Events\PoolConnectionCreated;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Events\PoolConnectionObtaining;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Events\PoolConnectionPushToPool;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Events\PoolConnectionRemoved;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Events\PoolCreated;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Events\PoolEvent;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\DriverConfigurationException;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Exception\DriverException;
use WeakMap;

use function gc_collect_cycles;
use function time;

final class ConnectionPool implements ConnectionPoolInterface
{
    private Channel $chan;

    /** @var WeakMap<PostgreSQL, ConnectionStats> */
    private WeakMap $map;

    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher,
        private readonly Closure $constructor,
        private readonly int $size,
        private readonly ?int $connectionTtl = null,
        private readonly ?int $connectionUseLimit = null
    ) {
        if ($this->size < 0) {
            throw new DriverConfigurationException('Expected, connection pull size > 0');
        }

        $this->chan = new Channel($this->size);
        /** @psalm-suppress PropertyTypeCoercion */
        $this->map = new WeakMap();

        $this->event(new PoolCreated($this));
    }

    /** @psalm-return array{PostgreSQL|null, ConnectionStats|null } */
    public function get(float $timeout = -1): array
    {
        if ($this->chan->isEmpty()) {
            /** try to fill pull with new connect */
            $this->make();
        }
        /** @var PostgreSQL|null $connection */
        $connection = $this->chan->pop($timeout);

        if (!$connection instanceof PostgreSQL) {
            return [null, null];
        }

        $this->event(
            new PoolConnectionObtaining($this->capacity(), $this->length(), $this->stats())
        );

        return [
            $connection,
            $this->map[$connection] ?? throw new DriverException('Connection stats could not be empty'),
        ];
    }

    public function put(PostgreSQL $connection): void
    {
        /** @var ?ConnectionStats $stats */
        $stats = $this->map[$connection] ?? null;

        $this->event(new PoolEvent('A Connection is putting into the Pool'));

        if ($stats === null || $stats->isOverdue()) {
            $this->remove($connection);

            return;
        }
        if ($this->chan->length() > $this->size) {
            $this->remove($connection);

            return;
        }

        /** to prevent hypothetical freeze if channel is full, will never happen but for sure */
        if (!$this->sendToChan($connection)) {
            $this->remove($connection);

            return;
        }
    }

    public function close(): void
    {
        $this->chan->close();

        $this->event(new PoolClosed());

        gc_collect_cycles();
    }

    public function capacity(): int
    {
        return $this->map->count();
    }

    public function length(): int
    {
        return $this->chan->length();
    }

    public function stats(): array
    {
        return $this->chan->stats();
    }

    /**
     * Exclude object data from doctrine cache serialization
     *
     * @see vendor/doctrine/dbal/src/Cache/QueryCacheProfile.php:127
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * @param string $data
     */
    public function __unserialize($data): void
    {
        // Do nothing
    }

    private function remove(PostgreSQL $connection): void
    {
        $this->map->offsetUnset($connection);
        unset($connection);

        $this->event(new PoolConnectionRemoved($this->capacity(), $this->length()));
    }

    private function make(): void
    {
        if ($this->size <= $this->capacity()) {
            return;
        }
        /** @var PostgreSQL $connection */
        $connection = ($this->constructor)();
        $this->event(new PoolConnectionCreated());

        /** Allocate to map only after successful push(exclude chanel overflow cause of concurrency)
         *
         * @psalm-suppress PossiblyNullReference
         */
        if ($this->sendToChan($connection)) {
            $this->map[$connection] = new ConnectionStats(time(), 1, $this->connectionTtl, $this->connectionUseLimit);
        }
    }

    private function sendToChan(PostgreSQL $connection): bool
    {
        if ($result = $this->chan->push($connection, 1)) {
            $this->event(
                new PoolConnectionPushToPool($this->capacity(), $this->length(), $this->stats())
            );
        }

        return $result;
    }

    private function event($event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
