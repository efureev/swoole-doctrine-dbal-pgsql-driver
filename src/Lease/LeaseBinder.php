<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Lease;

use SwooleDoctrinePool\Coroutine\CoroutineApi;
use SwooleDoctrinePool\Pool\Pool;

/**
 * Привязка lease к текущей корутине через её контекст. Дочерние корутины видят свой контекст,
 * поэтому получают собственные lease — одно PDO никогда не оказывается у двух корутин.
 */
final class LeaseBinder
{
    public const string CONTEXT_KEY = 'SwooleDoctrinePool.leases';

    private int $nextId = 1;

    public function __construct(private readonly CoroutineApi $api)
    {
    }

    /** Lease текущей корутины для пула, если он есть и не освобождён. Ничего не берёт из пула. */
    public function current(string $poolHash): ?Lease
    {
        if (!$this->api->inCoroutine()) {
            return null;
        }

        $set = $this->set(create: false);

        if ($set === null) {
            return null;
        }

        $lease = $set->leases[$poolHash] ?? null;

        if ($lease === null) {
            return null;
        }

        if ($lease->isReleased()) {
            unset($set->leases[$poolHash]);

            return null;
        }

        return $lease;
    }

    /**
     * Берёт соединение из пула и привязывает к корутине. defer регистрируется только после успешного
     * acquire и захватывает сам объект Lease: повторный lease в той же корутине (после close()) получит
     * собственный defer, а чужой не тронет.
     *
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function acquire(Pool $pool): Lease
    {
        $physical = $pool->acquire();
        $set = $this->set(create: true);
        $hash = $pool->key->hash;

        $lease = new Lease(
            id: $this->nextId++,
            ownerCid: $this->api->cid(),
            poolLabel: $pool->key->label,
            physical: $physical,
            pool: $pool,
            onRelease: static function (Lease $released) use ($set, $hash): void {
                if (($set->leases[$hash] ?? null) === $released) {
                    unset($set->leases[$hash]);
                }
            },
        );

        $this->api->defer(static fn() => $lease->release(viaDefer: true));
        $set->leases[$hash] = $lease;

        return $lease;
    }

    /** Освобождает все lease текущей корутины; вне корутины — 0. */
    public function releaseAll(): int
    {
        if (!$this->api->inCoroutine()) {
            return 0;
        }

        $set = $this->set(create: false);

        if ($set === null) {
            return 0;
        }

        $released = 0;

        foreach ($set->leases as $lease) {
            if (!$lease->isReleased()) {
                $lease->release();
                $released++;
            }
        }

        $set->leases = [];

        return $released;
    }

    private function set(bool $create): ?LeaseSet
    {
        $context = $this->api->context();
        $set = $context[self::CONTEXT_KEY] ?? null;

        if ($set instanceof LeaseSet) {
            return $set;
        }

        if (!$create) {
            return null;
        }

        $set = new LeaseSet();
        $context[self::CONTEXT_KEY] = $set;

        return $set;
    }
}
