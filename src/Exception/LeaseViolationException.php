<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Exception;

use LogicException;

use function sprintf;

/**
 * Намеренно НЕ Doctrine\DBAL\Driver\Exception: DBAL конвертирует и местами гасит driver-исключения,
 * а нарушение владения соединением — ошибка программы, которая должна дойти до разработчика как есть.
 */
final class LeaseViolationException extends LogicException implements Exception
{
    public static function released(int $leaseId, string $poolLabel, string $operation): self
    {
        return new self(sprintf(
            'Lease #%d пула %s уже освобождён, а %s пытается им воспользоваться: соединение могло уйти другой '
            . 'корутине. Statement/Result нельзя переживать запрос, в котором они созданы.',
            $leaseId,
            $poolLabel,
            $operation,
        ));
    }

    public static function foreignCoroutine(int $leaseId, string $poolLabel, int $ownerCid, int $cid, string $op): self
    {
        return new self(sprintf(
            'Lease #%d пула %s принадлежит корутине %d, а %s вызван из корутины %d. Одно PDO-соединение нельзя '
            . 'использовать из двух корутин: возьмите соединение заново в текущей.',
            $leaseId,
            $poolLabel,
            $ownerCid,
            $op,
            $cid,
        ));
    }

    public static function leaseNotFound(string $poolLabel): self
    {
        return new self(sprintf(
            'После обращения к драйверу у текущей корутины нет lease пула %s. Обычно это значит, что middleware '
            . 'переписал параметры соединения и драйвер с обёрткой считают разные ключи пула.',
            $poolLabel,
        ));
    }
}
