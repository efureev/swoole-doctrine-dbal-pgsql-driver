<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Coroutine;

use ArrayAccess;
use Closure;

/**
 * Всё, что пакету нужно от рантайма Swoole. Единственная точка, где допустимо обращение к Swoole\*,
 * поэтому ядро тестируется без расширения, а без корутины работает в прямом режиме.
 */
interface CoroutineApi
{
    /** -1 вне корутины (и всегда без ext-swoole). */
    public function cid(): int;

    public function inCoroutine(): bool;

    /**
     * Контекст текущей корутины: живёт до её завершения, у дочерних корутин — свой.
     *
     * @return ArrayAccess<string, mixed>
     *
     * @throws \SwooleDoctrinePool\Exception\UnsupportedRuntimeException вне корутины
     */
    public function context(): ArrayAccess;

    /**
     * Callback выполняется при завершении текущей корутины (LIFO), пока её cid и контекст ещё живы.
     *
     * @throws \SwooleDoctrinePool\Exception\UnsupportedRuntimeException вне корутины
     */
    public function defer(Closure $callback): void;

    /** Корутинный sleep внутри планировщика, обычный usleep вне его. */
    public function sleep(float $seconds): void;

    /** Периодический таймер; null, если планировщика нет (тогда обслуживание пула не запускается). */
    public function tick(float $seconds, Closure $callback): ?int;

    public function clearTimer(int $timerId): void;

    /** Включён ли хук SWOOLE_HOOK_PDO_PGSQL — без него PDO блокирует воркер. */
    public function pdoHookEnabled(): bool;
}
