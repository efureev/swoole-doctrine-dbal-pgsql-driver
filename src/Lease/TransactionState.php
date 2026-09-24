<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Lease;

use Doctrine\DBAL\TransactionIsolationLevel;

/**
 * Состояние транзакции DBAL-обёртки для одного lease: живёт вместе с соединением, на котором
 * транзакция открыта, и умирает вместе с ним — разойтись они не могут.
 */
final class TransactionState
{
    public int $level = 0;
    public bool $rollbackOnly = false;
    public ?TransactionIsolationLevel $isolation = null;

    public function reset(): void
    {
        $this->level = 0;
        $this->rollbackOnly = false;
        $this->isolation = null;
    }
}
