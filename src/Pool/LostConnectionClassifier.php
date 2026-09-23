<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use Doctrine\DBAL\Driver\Exception as DriverException;
use PDOException;
use Throwable;

use function in_array;
use function is_string;
use function str_contains;
use function str_starts_with;

/**
 * Отличает «соединение мертво» от прочих ошибок. Только для таких соединение уничтожается вместо
 * возврата в пул; constraint-ошибки и deadlock под это не попадают — соединение после них исправно.
 */
final class LostConnectionClassifier
{
    /** @var list<string> SQLSTATE class 08 целиком плюс operator intervention (57P0x). */
    private const array SQLSTATES = ['57P01', '57P02', '57P03', '57P05'];

    /** @var list<string> */
    private const array MESSAGES = [
        'server closed the connection unexpectedly',
        'terminating connection',
        'no connection to the server',
        'could not send data to server',
        'could not receive data from server',
        'SSL SYSCALL error',
        'connection not open',
        'Connection refused',
        'timeout expired',
        'is dead or not enabled',
        'server conn crashed',
    ];

    public function isLost(Throwable $exception): bool
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $sqlState = $this->sqlState($e);

            if ($sqlState !== null && str_starts_with($sqlState, '08')) {
                return true;
            }

            if ($sqlState !== null && in_array($sqlState, self::SQLSTATES, true)) {
                return true;
            }

            foreach (self::MESSAGES as $needle) {
                if (str_contains($e->getMessage(), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sqlState(Throwable $e): ?string
    {
        if ($e instanceof DriverException) {
            return $e->getSQLState();
        }

        if ($e instanceof PDOException && isset($e->errorInfo[0]) && is_string($e->errorInfo[0])) {
            return $e->errorInfo[0];
        }

        return null;
    }
}
