<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

enum CloseReason: string
{
    case MaxLifetime = 'max_lifetime';
    case MaxUses = 'max_uses';
    case IdleTimeout = 'idle_timeout';
    case Broken = 'broken';
    case ValidationFailed = 'validation_failed';
    case RollbackFailed = 'rollback_failed';
    case ResetFailed = 'reset_failed';
    case PoolClosed = 'pool_closed';
    case Drained = 'drained';
}
