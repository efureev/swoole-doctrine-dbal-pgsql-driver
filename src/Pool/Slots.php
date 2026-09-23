<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

/**
 * Счётный семафор на размер пула. Право держать физическое соединение — это токен: он берётся ДО
 * подключения (которое отдаёт управление планировщику), поэтому больше size соединений не откроется
 * никогда, сколько бы корутин ни пришло одновременно.
 */
interface Slots
{
    public function acquire(float $timeout): SlotResult;

    /** Без ожидания: для прогрева и обслуживания. */
    public function tryAcquire(): bool;

    /**
     * Никогда не блокирует: сумма токенов в семафоре и на руках равна size.
     *
     * @throws \LogicException если инвариант нарушен — это баг, а не рабочая ситуация
     */
    public function release(): void;

    public function available(): int;

    public function waiting(): int;

    /** Будит всех ожидающих результатом Closed; последующие release() — no-op. */
    public function close(): void;
}
