<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use Closure;
use Doctrine\DBAL\Driver as DoctrineDriver;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\Coroutine\CoroutineApi;
use SwooleDoctrinePool\Coroutine\SwooleCoroutineApi;
use SwooleDoctrinePool\Event\EventEmitter;
use SwooleDoctrinePool\Lease\LeaseBinder;

use function count;
use function getmypid;

/**
 * Пулы одного воркера, по одному на DSN. Один экземпляр на процесс (в SWOOLE_THREAD — на поток:
 * объекты PHP между потоками не разделяются, так что изоляция получается сама собой).
 */
final class PoolRegistry
{
    /** @var array<string, Pool> hash => pool */
    private array $pools = [];
    private ?int $pid = null;
    private ?DoctrineDriver $innerDriver = null;
    private bool $hookWarned = false;
    private readonly CoroutineApi $api;
    private readonly Clock $clock;
    private readonly EventEmitter $events;
    private readonly LeaseBinder $binder;
    /** @var Closure(int): Slots */
    private readonly Closure $slotsFactory;

    /** @param ?Closure(int): Slots $slotsFactory подмена семафора в тестах */
    public function __construct(
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        ?CoroutineApi $api = null,
        ?Clock $clock = null,
        ?Closure $slotsFactory = null,
    ) {
        $this->api = $api ?? SwooleCoroutineApi::create();
        $this->clock = $clock ?? new MonotonicClock();
        $this->events = new EventEmitter($dispatcher, $logger);
        $this->binder = new LeaseBinder($this->api);
        $this->slotsFactory = $slotsFactory ?? static fn(int $size): Slots => new ChannelSlots($size);
    }

    public function api(): CoroutineApi
    {
        return $this->api;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function events(): EventEmitter
    {
        return $this->events;
    }

    public function binder(): LeaseBinder
    {
        return $this->binder;
    }

    /**
     * Драйвер, через который открываются физические соединения (обычно pdo_pgsql DBAL). Запоминает
     * первый, чтобы прогрев мог создать пул по параметрам соединения без доступа к его драйверу.
     */
    public function rememberInnerDriver(DoctrineDriver $driver): void
    {
        $this->innerDriver ??= $driver;
    }

    public function innerDriver(): ?DoctrineDriver
    {
        return $this->innerDriver;
    }

    /**
     * Пул для ключа; создаётся лениво при первом обращении из корутины. Пул, унаследованный от
     * родительского процесса (создан до fork), выбрасывается: его сокеты разделены с родителем.
     */
    public function poolFor(PoolKey $key, PoolConfig $config, ConnectionFactory $factory): Pool
    {
        $this->guardProcess();

        $pool = $this->pools[$key->hash] ?? null;

        if ($pool !== null && !$pool->isClosed()) {
            return $pool;
        }

        $this->warnIfHookDisabled();

        return $this->pools[$key->hash] = new Pool(
            $key,
            $config,
            $factory,
            ($this->slotsFactory)($config->size),
            $this->api,
            $this->clock,
            $this->events,
        );
    }

    public function get(string $hash): ?Pool
    {
        return $this->pools[$hash] ?? null;
    }

    /** @return array<string, Pool> */
    public function all(): array
    {
        return $this->pools;
    }

    /** @return array<string, PoolStats> label => stats */
    public function stats(): array
    {
        $stats = [];

        foreach ($this->pools as $pool) {
            $stats[$pool->key->label] = $pool->stats();
        }

        return $stats;
    }

    public function warmUpAll(): void
    {
        foreach ($this->pools as $pool) {
            $pool->warmUp();
        }
    }

    /**
     * Воркер уходит, но запросы ещё в полёте (worker_exit при reload_async): таймеры сняты,
     * простаивающие закрыты, занятые закроются при возврате. Идемпотентно.
     */
    public function drainAll(): void
    {
        foreach ($this->pools as $pool) {
            $pool->drain();
        }
    }

    /** Идемпотентно; гасит таймеры обслуживания — иначе воркер ждёт max_wait_time на reload. */
    public function closeAll(float $drainTimeout = 5.0): void
    {
        foreach ($this->pools as $pool) {
            $pool->close($drainTimeout);
        }

        $this->pools = [];
    }

    /** Освобождает lease текущей корутины во всех пулах. Вне корутины — 0. */
    public function releaseCurrentCoroutine(): int
    {
        return $this->binder->releaseAll();
    }

    public function count(): int
    {
        return count($this->pools);
    }

    /**
     * Без хука pdo_pgsql PDO блокирует процесс на время запроса. В консоли и в master это допустимо —
     * там одна корутина; в HTTP-воркере это потеря конкурентности, а не данных, поэтому warning,
     * а не отказ: иначе первая же консольная команда падала бы.
     */
    private function warnIfHookDisabled(): void
    {
        if ($this->hookWarned || !$this->api->inCoroutine() || $this->api->pdoHookEnabled()) {
            return;
        }

        $this->hookWarned = true;
        $this->events->logger()->warning(
            'swoole_pool: SWOOLE_HOOK_PDO_PGSQL не включён — PDO блокирует процесс на время каждого запроса. '
            . 'Для HTTP-воркера добавьте SWOOLE_HOOK_PDO_PGSQL (входит в SWOOLE_HOOK_ALL) в hook_flags; '
            . 'Swoole должен быть собран с --enable-swoole-pgsql.',
        );
    }

    private function guardProcess(): void
    {
        $pid = getmypid();

        if ($this->pid === null) {
            $this->pid = $pid;

            return;
        }

        if ($this->pid === $pid) {
            return;
        }

        if ($this->pools !== []) {
            $this->events->logger()->warning(
                'swoole_pool: пулы созданы в процессе {parent}, а используются в {pid} — унаследованные после fork '
                . 'соединения отброшены. Не обращайтесь к БД до старта воркера.',
                ['parent' => $this->pid, 'pid' => $pid, 'pools' => count($this->pools)],
            );
        }

        // Без close(): закрытие отправило бы Terminate по сокету, который разделён с родителем.
        $this->pools = [];
        $this->pid = $pid;
    }
}
