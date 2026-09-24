<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Config;

/**
 * Что делать с сессией Postgres при возврате соединения в пул. ROLLBACK незавершённой транзакции
 * выполняется всегда, независимо от политики.
 */
enum ResetPolicy: string
{
    /** DISCARD ALL: сбрасывает SET, temp-таблицы, advisory locks, LISTEN, prepared statements. */
    case Discard = 'discard';

    /** Только ROLLBACK: быстрее на один round-trip, но состояние сессии переживает запрос. */
    case RollbackOnly = 'rollback_only';
}
