<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration\Support;

use Closure;
use Doctrine\DBAL\Tools\DsnParser;
use PDO;
use Swoole\Coroutine;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use Throwable;

use function getenv;
use function getmypid;
use function is_string;
use function preg_replace;
use function sprintf;
use function strrchr;
use function strtolower;
use function substr;

use function Swoole\Coroutine\run;

/**
 * Тестовая БД из POOL_TEST_DSN: своя схема на класс тестов, параметры DBAL для пула и административные
 * действия «со стороны» (pg_terminate_backend, pg_stat_activity). После первого Coroutine\run() хуки
 * Swoole остаются включёнными, и PDO вне корутины запрещён — поэтому admin-операции вне корутины
 * оборачиваются в собственный run().
 */
final class Database
{
    /** @var array<string, mixed> */
    private readonly array $params;
    private ?string $serverVersion = null;

    private function __construct(array $params, public readonly string $schema)
    {
        $this->params = $params;
    }

    public static function fromEnv(string $testClass): ?self
    {
        $dsn = getenv('POOL_TEST_DSN');

        if (!is_string($dsn) || $dsn === '') {
            return null;
        }

        $parser = new DsnParser(['pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);
        $params = $parser->parse($dsn);
        unset($params['driver']);

        $short = strtolower((string)preg_replace('/[^a-z0-9]/i', '', substr((string)strrchr($testClass, '\\'), 1)));
        $schema = sprintf('t_%s_%d', $short, (int)getmypid());

        $database = new self($params, $schema);
        $database->exec(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
        $database->exec(sprintf('CREATE SCHEMA "%s"', $schema));

        return $database;
    }

    /**
     * @param array<string, mixed> $pool опции пула (driverOptions['pool'])
     *
     * @return array<string, mixed>
     */
    public function params(array $pool = [], string $applicationName = 'swoole-doctrine-pool-tests'): array
    {
        return $this->params + [
            'wrapperClass' => CoroutineSafeConnection::class,
            'serverVersion' => $this->serverVersion(),
            'application_name' => $applicationName,
            'driverOptions' => ['pool' => $pool],
        ];
    }

    public function table(string $name): string
    {
        return sprintf('"%s"."%s"', $this->schema, $name);
    }

    public function exec(string $sql): void
    {
        $this->withAdmin(static function (PDO $pdo) use ($sql): void {
            $pdo->exec($sql);
        });
    }

    /** Число бэкендов с этим application_name в состоянии внутри транзакции (active или idle in transaction). */
    public function backendsInTransaction(string $applicationName): int
    {
        return $this->withAdmin(static function (PDO $pdo) use ($applicationName): int {
            $statement = $pdo->prepare(
                "SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND xact_start IS NOT NULL",
            );
            $statement->execute([$applicationName]);

            return (int)$statement->fetchColumn();
        });
    }

    public function backendState(int $pid): ?string
    {
        return $this->withAdmin(static function (PDO $pdo) use ($pid): ?string {
            $statement = $pdo->prepare('SELECT state FROM pg_stat_activity WHERE pid = ?');
            $statement->execute([$pid]);
            $state = $statement->fetchColumn();

            return is_string($state) ? $state : null;
        });
    }

    public function terminateBackend(int $pid): void
    {
        $this->exec('SELECT pg_terminate_backend(' . $pid . ')');
    }

    public function drop(): void
    {
        $this->exec(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $this->schema));
    }

    private function serverVersion(): string
    {
        return $this->serverVersion ??= $this->withAdmin(
            static fn(PDO $pdo): string => (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        );
    }

    /**
     * @template T
     *
     * @param Closure(PDO): T $action
     *
     * @return T
     */
    private function withAdmin(Closure $action): mixed
    {
        $run = function () use ($action): mixed {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;application_name=admin',
                (string)($this->params['host'] ?? 'localhost'),
                (string)($this->params['port'] ?? 5432),
                (string)($this->params['dbname'] ?? ''),
            );
            $pdo = new PDO($dsn, (string)($this->params['user'] ?? ''), (string)($this->params['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            return $action($pdo);
        };

        if (Coroutine::getCid() > 0) {
            return $run();
        }

        $result = null;
        $error = null;

        run(static function () use ($run, &$result, &$error): void {
            try {
                $result = $run();
            } catch (Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }

        /** @var T $result */
        return $result;
    }
}
