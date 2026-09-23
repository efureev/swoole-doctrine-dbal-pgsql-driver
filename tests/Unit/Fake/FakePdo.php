<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Override;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO без соединения: родительский конструктор не вызывается, все используемые пулом методы
 * переопределены. Ошибки задаются по имени метода: $pdo->failOn['query'] = new PDOException(...).
 */
final class FakePdo extends PDO
{
    /** @var list<string> */
    public array $log = [];
    /** @var array<string, PDOException> */
    public array $failOn = [];
    public bool $inTransaction = false;
    public string $serverVersion = '17.2';
    public string $lastInsertId = '42';

    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
    public function __construct()
    {
    }

    #[Override]
    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    #[Override]
    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_SERVER_VERSION => $this->serverVersion,
            PDO::ATTR_DRIVER_NAME => 'pgsql',
            default => null,
        };
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    #[Override]
    public function beginTransaction(): bool
    {
        $this->record('beginTransaction');
        $this->inTransaction = true;

        return true;
    }

    #[Override]
    public function commit(): bool
    {
        $this->record('commit');
        $this->inTransaction = false;

        return true;
    }

    #[Override]
    public function rollBack(): bool
    {
        $this->record('rollBack');
        $this->inTransaction = false;

        return true;
    }

    #[Override]
    public function exec(string $statement): int|false
    {
        $this->record('exec:' . $statement);

        return 1;
    }

    #[Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->record('query:' . $query);

        return false;
    }

    #[Override]
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . $string . "'";
    }

    #[Override]
    public function lastInsertId(?string $name = null): string|false
    {
        $this->record('lastInsertId');

        return $this->lastInsertId;
    }

    private function record(string $entry): void
    {
        $method = explode(':', $entry, 2)[0];

        if (isset($this->failOn[$method])) {
            $this->log[] = $entry . ' [failed]';

            throw $this->failOn[$method];
        }

        $this->log[] = $entry;
    }
}
