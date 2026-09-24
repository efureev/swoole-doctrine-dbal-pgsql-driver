<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Exception;

use RuntimeException;

use function get_debug_type;
use function sprintf;

final class UnsupportedRuntimeException extends RuntimeException implements Exception
{
    public static function notPdo(mixed $native): self
    {
        return new self(sprintf(
            'Внутренний драйвер вернул нативное соединение %s, ожидался PDO. Пул рассчитан на pdo_pgsql.',
            get_debug_type($native),
        ));
    }

    public static function notInCoroutine(string $operation): self
    {
        return new self(sprintf('%s возможно только внутри корутины Swoole.', $operation));
    }
}
