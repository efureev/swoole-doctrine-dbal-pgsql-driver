<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Exception;

use InvalidArgumentException;

use function get_debug_type;
use function implode;
use function sprintf;

final class InvalidConfigurationException extends InvalidArgumentException implements Exception
{
    /** @param list<string> $known */
    public static function unknownKey(string $key, array $known): self
    {
        return new self(sprintf(
            'Неизвестная опция пула "%s". Допустимые: %s.',
            $key,
            implode(', ', $known),
        ));
    }

    public static function invalidValue(string $key, mixed $value, string $expected): self
    {
        return new self(sprintf(
            'Опция пула "%s": ожидается %s, получено %s.',
            $key,
            $expected,
            get_debug_type($value),
        ));
    }

    public static function constraint(string $message): self
    {
        return new self($message);
    }

    public static function persistentNotAllowed(): self
    {
        return new self(
            'Параметр DBAL "persistent" несовместим с пулом: persistent-PDO живёт вне пула и не сбрасывается '
            . 'между запросами. Уберите его — пул сам переиспользует соединения.'
        );
    }

    public static function wrapperClassRequired(?string $given): self
    {
        return new self(sprintf(
            'Пул требует wrapperClass, наследующий SwooleDoctrinePool\DBAL\CoroutineSafeConnection, получено %s. '
            . 'Без него Doctrine\DBAL\Connection хранит состояние транзакции в одном поле для всех корутин.',
            $given ?? 'null',
        ));
    }

    public static function notAPostgresDriver(string $class): self
    {
        return new self(sprintf(
            'Пул оборачивает только PostgreSQL-драйверы (AbstractPostgreSQLDriver), получен %s. '
            . 'Укажите driver: pdo_pgsql или url со схемой postgresql://.',
            $class,
        ));
    }
}
