<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use PDOException;
use SwooleDoctrinePool\Config\PoolConfig;
use SwooleDoctrinePool\Config\ResetPolicy;
use SwooleDoctrinePool\Coroutine\CoroutineApi;
use SwooleDoctrinePool\Event\AcquireTimedOut;
use SwooleDoctrinePool\Event\ConnectionClosed;
use SwooleDoctrinePool\Event\ConnectionOpened;
use SwooleDoctrinePool\Event\EventEmitter;
use SwooleDoctrinePool\Event\LeaseAcquired;
use SwooleDoctrinePool\Event\LeaseReleased;
use SwooleDoctrinePool\Event\PoolClosed;
use SwooleDoctrinePool\Event\PoolCreated;
use SwooleDoctrinePool\Event\RolledBackOnRelease;
use SwooleDoctrinePool\Exception\AcquireTimeoutException;
use SwooleDoctrinePool\Exception\PoolClosedException;
use Throwable;

use function array_pop;
use function array_shift;
use function array_values;
use function count;

/**
 * Пул физических соединений одного DSN в одном воркере.
 *
 * Учёт: slots (токены = право держать соединение: inUse + connecting) + idle (LIFO-стек PHP-массива).
 * Простаивающее соединение токена не держит — токен возвращается вместе с ним в idle, и именно это
 * будит ожидающих acquire. Инварианты: inUse + connecting + available == size;
 * idle + inUse + connecting <= size. Ни одна операция не зависит от GC и не дренирует idle через
 * канал — терять соединения негде.
 */
final class Pool
{
    /** @var list<PhysicalConnection> вершина стека — последний элемент */
    private array $idle = [];
    private int $inUse = 0;
    private int $connecting = 0;
    private int $nextId = 1;
    private bool $draining = false;
    private bool $closing = false;
    private bool $closed = false;
    private bool $maintaining = false;
    private ?int $timerId = null;
    private ?string $serverVersion = null;

    private int $createdTotal = 0;
    private int $closedTotal = 0;
    private int $acquiredTotal = 0;
    private int $acquireTimeoutsTotal = 0;
    private int $rollbacksOnReleaseTotal = 0;
    private int $validationFailuresTotal = 0;

    public function __construct(
        public readonly PoolKey $key,
        public readonly PoolConfig $config,
        private readonly ConnectionFactory $factory,
        private readonly Slots $slots,
        private readonly CoroutineApi $api,
        private readonly Clock $clock,
        private readonly EventEmitter $events,
    ) {
        $this->events->emit(fn(): PoolCreated => new PoolCreated($key->label, $config));

        // Таймер только по явной настройке: живой таймер не даёт завершиться консольной команде,
        // демону и воркеру при reload_async — без него простаивающие закрываются лениво при release.
        if ($config->maintenanceInterval > 0.0) {
            $this->timerId = $api->tick($config->maintenanceInterval, $this->maintain(...));
        }
    }

    /**
     * @throws AcquireTimeoutException
     * @throws PoolClosedException
     * @throws \Doctrine\DBAL\Driver\Exception при ошибке подключения
     */
    public function acquire(): PhysicalConnection
    {
        if ($this->closed || $this->closing) {
            throw PoolClosedException::forPool($this->key->label);
        }

        $startedAt = $this->clock->now();

        $result = $this->slots->acquire($this->config->acquireTimeout);

        if ($result === SlotResult::Closed) {
            throw PoolClosedException::forPool($this->key->label);
        }

        if ($result === SlotResult::TimedOut) {
            $waited = $this->clock->now() - $startedAt;
            $this->acquireTimeoutsTotal++;
            $this->events->emit(fn(): AcquireTimedOut => new AcquireTimedOut(
                $this->key->label,
                $this->api->cid(),
                $waited,
                $this->stats(),
            ));

            throw AcquireTimeoutException::afterWaiting(
                $this->key->label,
                $waited,
                $this->config->size,
                $this->slots->waiting(),
            );
        }

        // Токен на руках: с этого места любой выход, кроме return, обязан вернуть его через destroy()/release().
        if ($this->isClosing()) {
            $this->slots->release();

            throw PoolClosedException::forPool($this->key->label);
        }

        while (($connection = array_pop($this->idle)) !== null) {
            $now = $this->clock->now();
            $reason = $connection->expiredReason($this->config, $now);

            if ($reason !== null) {
                $this->destroy($connection, $reason, releaseSlot: false);
                continue;
            }

            if (!$this->validate($connection, $now)) {
                $this->destroy($connection, CloseReason::ValidationFailed, releaseSlot: false);
                continue;
            }

            return $this->handOut($connection, $startedAt, fromIdle: true);
        }

        $this->connecting++;

        try {
            $connection = $this->factory->open($this->nextId++);
        } catch (Throwable $e) {
            $this->connecting--;
            $this->slots->release();

            throw $e;
        }

        $this->connecting--;
        $this->createdTotal++;
        $this->serverVersion ??= $connection->serverVersion;
        $this->events->emit(fn(): ConnectionOpened => new ConnectionOpened(
            $this->key->label,
            $connection->id,
            $this->clock->now() - $connection->createdAt,
        ));

        return $this->handOut($connection, $startedAt, fromIdle: false);
    }

    /**
     * Никогда не бросает: любая ошибка на пути возврата заканчивается уничтожением соединения.
     * ROLLBACK незавершённой транзакции выполняется всегда — пул ничего не коммитит за приложение.
     */
    public function release(PhysicalConnection $connection, bool $broken, bool $viaDefer = false): void
    {
        $this->inUse--;
        $heldSince = $connection->lastReleasedAt;
        $rolledBack = false;

        if ($broken || $connection->broken) {
            $this->destroy($connection, CloseReason::Broken);

            return;
        }

        try {
            if ($connection->pdo()->inTransaction()) {
                $connection->pdo()->rollBack();
                $rolledBack = true;
                $this->rollbacksOnReleaseTotal++;
                $this->events->logger()->warning(
                    'swoole_pool: соединение возвращено с открытой транзакцией — выполнен ROLLBACK',
                    ['pool' => $this->key->label, 'connection' => $connection->id, 'cid' => $this->api->cid()],
                );
                $this->events->emit(fn(): RolledBackOnRelease => new RolledBackOnRelease(
                    $this->key->label,
                    $this->api->cid(),
                    $connection->id,
                    $viaDefer,
                ));
            }
        } catch (Throwable $e) {
            $this->events->logger()->error('swoole_pool: ROLLBACK при возврате соединения не удался', [
                'pool' => $this->key->label,
                'connection' => $connection->id,
                'exception' => $e,
            ]);
            $this->destroy($connection, CloseReason::RollbackFailed);

            return;
        }

        if ($this->closing || $this->draining) {
            $this->destroy($connection, $this->closing ? CloseReason::PoolClosed : CloseReason::Drained);

            return;
        }

        $now = $this->clock->now();
        $reason = $connection->expiredReason($this->config, $now);

        if ($reason !== null) {
            $this->destroy($connection, $reason);

            return;
        }

        if ($this->config->resetOnRelease === ResetPolicy::Discard) {
            try {
                $connection->pdo()->exec('DISCARD ALL');
            } catch (Throwable $e) {
                $this->events->logger()->error('swoole_pool: DISCARD ALL при возврате соединения не удался', [
                    'pool' => $this->key->label,
                    'connection' => $connection->id,
                    'exception' => $e,
                ]);
                $this->destroy($connection, CloseReason::ResetFailed);

                return;
            }
        }

        $connection->lastReleasedAt = $now;
        $this->idle[] = $connection;
        $this->slots->release();
        $this->sweepIdle($now);

        $this->events->emit(fn(): LeaseReleased => new LeaseReleased(
            $this->key->label,
            $this->api->cid(),
            $connection->id,
            $now - $heldSince,
            $rolledBack,
            $viaDefer,
        ));
    }

    /**
     * Обслуживание по таймеру: закрыть простаивающие сверх min_idle и пережившие max_lifetime,
     * дотянуть до min_idle. Из idle сначала вырезается, потом закрывается — двойная выдача невозможна.
     */
    public function maintain(): void
    {
        if ($this->maintaining || $this->draining || $this->closing || $this->closed) {
            return;
        }

        $this->maintaining = true;

        try {
            $now = $this->clock->now();
            $toClose = [];
            $keep = count($this->idle);

            foreach ($this->idle as $index => $connection) {
                $reason = $connection->expiredReason($this->config, $now);

                if (
                    $reason === null
                    && $this->config->idleTimeout !== null
                    && $keep > $this->config->minIdle
                    && $connection->idleFor($now) >= $this->config->idleTimeout
                ) {
                    $reason = CloseReason::IdleTimeout;
                }

                if ($reason !== null) {
                    $toClose[$index] = $reason;
                    $keep--;
                }
            }

            foreach ($toClose as $index => $reason) {
                $connection = $this->idle[$index];
                unset($this->idle[$index]);
                $this->destroy($connection, $reason, releaseSlot: false);
            }

            if ($toClose !== []) {
                $this->idle = array_values($this->idle);
            }

            $this->warmUp();
        } finally {
            $this->maintaining = false;
        }
    }

    /** Открывает соединения до min_idle, не превышая size и не ожидая занятых слотов. */
    public function warmUp(): void
    {
        if ($this->draining || $this->closing || $this->closed) {
            return;
        }

        while (count($this->idle) + $this->inUse + $this->connecting < $this->config->minIdle) {
            if (!$this->slots->tryAcquire()) {
                return;
            }

            $this->connecting++;

            try {
                $connection = $this->factory->open($this->nextId++);
            } catch (Throwable $e) {
                $this->connecting--;
                $this->slots->release();
                $this->events->logger()->warning('swoole_pool: прогрев пула не удался', [
                    'pool' => $this->key->label,
                    'exception' => $e,
                ]);

                return;
            }

            $this->connecting--;
            $this->createdTotal++;
            $this->serverVersion ??= $connection->serverVersion;
            $this->events->emit(fn(): ConnectionOpened => new ConnectionOpened(
                $this->key->label,
                $connection->id,
                $this->clock->now() - $connection->createdAt,
            ));

            if ($this->isClosing()) {
                $this->destroy($connection, CloseReason::PoolClosed, releaseSlot: true);

                return;
            }

            $connection->lastReleasedAt = $this->clock->now();
            $this->idle[] = $connection;
            $this->slots->release();
        }
    }

    /**
     * Воркер уходит (worker_exit при reload_async): таймер снят, простаивающие закрыты, возвращаемые
     * соединения закрываются вместо возврата в idle — но запросы в полёте продолжают получать
     * соединения. Полное закрытие с отказом новым acquire — close() на worker_stop.
     */
    public function drain(): void
    {
        if ($this->draining || $this->closing || $this->closed) {
            return;
        }

        $this->draining = true;
        $this->stopTimer();

        while (($connection = array_pop($this->idle)) !== null) {
            $this->destroy($connection, CloseReason::Drained, releaseSlot: false);
        }
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    /**
     * Закрывает простаивающие сразу, занятые — как только их вернут (release видит closing),
     * ждёт возврата до drainTimeout, затем будит ожидающих acquire ошибкой PoolClosed. Идемпотентно.
     */
    public function close(float $drainTimeout = 5.0): void
    {
        if ($this->closed) {
            return;
        }

        $this->closing = true;
        $this->stopTimer();

        while (($connection = array_pop($this->idle)) !== null) {
            $this->destroy($connection, CloseReason::PoolClosed, releaseSlot: false);
        }

        $deadline = $this->clock->now() + $drainTimeout;

        while ($this->inUse > 0 && $this->clock->now() < $deadline) {
            $this->api->sleep(0.05);
        }

        $this->slots->close();
        $this->closed = true;

        if ($this->inUse > 0) {
            $this->events->logger()->warning('swoole_pool: пул закрыт, пока соединения ещё на руках', [
                'pool' => $this->key->label,
                'in_use' => $this->inUse,
            ]);
        }

        $this->events->emit(fn(): PoolClosed => new PoolClosed($this->key->label, $this->stats()));
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Вызов метода, а не чтение поля: пока acquire() ждал слот или коннект, другая корутина могла начать close(). */
    private function isClosing(): bool
    {
        return $this->closing;
    }

    private function stopTimer(): void
    {
        if ($this->timerId !== null) {
            $this->api->clearTimer($this->timerId);
            $this->timerId = null;
        }
    }

    /**
     * Ленивая чистка со дна стека (там самые давно возвращённые): просроченные по max_lifetime/max_uses
     * закрываются всегда, простоявшие дольше idle_timeout — пока idle больше min_idle. Без таймера
     * в полной тишине ничего не закрывается — до первого возврата соединения.
     */
    private function sweepIdle(float $now): void
    {
        while (($oldest = $this->idle[0] ?? null) !== null) {
            $reason = $oldest->expiredReason($this->config, $now);

            if (
                $reason === null
                && $this->config->idleTimeout !== null
                && count($this->idle) > $this->config->minIdle
                && $oldest->idleFor($now) >= $this->config->idleTimeout
            ) {
                $reason = CloseReason::IdleTimeout;
            }

            if ($reason === null) {
                return;
            }

            array_shift($this->idle);
            $this->destroy($oldest, $reason, releaseSlot: false);
        }
    }

    public function serverVersion(): ?string
    {
        return $this->serverVersion;
    }

    public function stats(): PoolStats
    {
        return new PoolStats(
            label: $this->key->label,
            size: $this->config->size,
            idle: count($this->idle),
            inUse: $this->inUse,
            connecting: $this->connecting,
            waiting: $this->slots->waiting(),
            createdTotal: $this->createdTotal,
            closedTotal: $this->closedTotal,
            acquiredTotal: $this->acquiredTotal,
            acquireTimeoutsTotal: $this->acquireTimeoutsTotal,
            rollbacksOnReleaseTotal: $this->rollbacksOnReleaseTotal,
            validationFailuresTotal: $this->validationFailuresTotal,
        );
    }

    private function handOut(PhysicalConnection $connection, float $startedAt, bool $fromIdle): PhysicalConnection
    {
        $connection->uses++;
        $this->inUse++;
        $this->acquiredTotal++;
        // lastReleasedAt переиспользуется как «взято в», чтобы LeaseReleased знал время удержания.
        $connection->lastReleasedAt = $this->clock->now();

        $this->events->emit(fn(): LeaseAcquired => new LeaseAcquired(
            $this->key->label,
            $this->api->cid(),
            $connection->id,
            $connection->lastReleasedAt - $startedAt,
            $fromIdle,
        ));

        return $connection;
    }

    private function validate(PhysicalConnection $connection, float $now): bool
    {
        $threshold = $this->config->validateIdleAfter;

        if ($threshold === null || $connection->idleFor($now) < $threshold) {
            return true;
        }

        try {
            $connection->pdo()->query('SELECT 1');

            return true;
        } catch (PDOException) {
            $this->validationFailuresTotal++;

            return false;
        }
    }

    /**
     * @param bool $releaseSlot true — соединение было на руках (токен держится) и токен возвращается;
     *                          false — соединение из idle (токена нет) или токен остаётся у вызывающего
     */
    private function destroy(PhysicalConnection $connection, CloseReason $reason, bool $releaseSlot = true): void
    {
        $now = $this->clock->now();
        $age = $connection->age($now);
        $uses = $connection->uses;

        $connection->close();
        $this->closedTotal++;

        if ($releaseSlot) {
            $this->slots->release();
        }

        $this->events->emit(fn(): ConnectionClosed => new ConnectionClosed(
            $this->key->label,
            $connection->id,
            $reason,
            $age,
            $uses,
        ));
    }
}
