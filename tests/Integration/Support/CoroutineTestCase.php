<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration\Support;

use Closure;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use SwooleDoctrinePool\Pool\Pool;
use SwooleDoctrinePool\Pool\PoolRegistry;
use Throwable;

use function array_key_first;
use function extension_loaded;
use function getenv;

use function Swoole\Coroutine\run;

/**
 * Тело теста выполняется внутри планировщика Swoole с собственным PoolRegistry. closeAll() в finally
 * обязателен: таймер обслуживания пула держит event loop, и run() без него не вернётся.
 */
#[RequiresPhpExtension('swoole')]
abstract class CoroutineTestCase extends TestCase
{
    protected static ?Database $database = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        // Атрибут RequiresPhpExtension срабатывает позже этого хука, поэтому guard дублируется.
        if (!extension_loaded('swoole') || getenv('POOL_TEST_DSN') === false) {
            return;
        }

        static::$database = Database::fromEnv(static::class);
    }

    #[\Override]
    protected function setUp(): void
    {
        if (getenv('POOL_TEST_DSN') === false) {
            self::markTestSkipped('POOL_TEST_DSN не задан — см. tests/Integration/README.md');
        }
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        static::$database?->drop();
        static::$database = null;
    }

    /** @param Closure(PoolRegistry): void $body */
    protected function runInCoroutine(Closure $body, ?EventDispatcherInterface $dispatcher = null): void
    {
        $error = null;

        run(static function () use ($body, $dispatcher, &$error): void {
            $registry = new PoolRegistry(dispatcher: $dispatcher);

            try {
                $body($registry);
            } catch (Throwable $e) {
                $error = $e;
            } finally {
                $registry->closeAll(2.0);
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }

    protected static function db(): Database
    {
        return static::$database ?? self::fail('База тестов не инициализирована');
    }

    /** @param array<string, mixed> $pool */
    protected static function connection(
        PoolRegistry $registry,
        array $pool = [],
        string $applicationName = 'swoole-doctrine-pool-tests',
    ): CoroutineSafeConnection {
        return new CoroutineSafeConnection(
            self::db()->params($pool, $applicationName),
            new Driver(registry: $registry),
        );
    }

    protected static function table(string $name): string
    {
        return self::db()->table($name);
    }

    protected static function pool(PoolRegistry $registry): Pool
    {
        $pools = $registry->all();
        $first = array_key_first($pools);

        return $first === null ? self::fail('Пул ещё не создан') : $pools[$first];
    }

    protected static function backendPid(CoroutineSafeConnection $connection): int
    {
        return (int)$connection->fetchOne('SELECT pg_backend_pid()');
    }
}
