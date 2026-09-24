<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swoole\Runtime;
use SwooleDoctrinePool\Tests\Integration\Support\CoroutineTestCase;

use function hrtime;

use function Swoole\Coroutine\run;

/**
 * Предпосылка всего пакета: pdo_pgsql под хуком отдаёт управление планировщику. Если этот тест
 * красный, остальное бессмысленно — PDO блокирует воркер.
 */
#[CoversNothing]
final class HookSanityTest extends CoroutineTestCase
{
    public function testThePdoPgsqlHookIsEnabledInsideTheScheduler(): void
    {
        $flags = 0;

        run(static function () use (&$flags): void {
            $flags = Runtime::getHookFlags();
        });

        self::assertGreaterThan(0, $flags & SWOOLE_HOOK_PDO_PGSQL);
    }

    public function testTwoSleepsOnSeparateConnectionsRunConcurrently(): void
    {
        $elapsed = 0.0;

        run(static function () use (&$elapsed): void {
            $params = self::db()->params();
            $group = new WaitGroup();
            $startedAt = hrtime(true);

            for ($i = 0; $i < 2; $i++) {
                $group->add();
                Coroutine::create(static function () use ($params, $group): void {
                    $pdo = new PDO(
                        'pgsql:host=' . $params['host'] . ';port=' . ($params['port'] ?? 5432) . ';dbname=' . $params['dbname'],
                        (string)$params['user'],
                        (string)($params['password'] ?? ''),
                    );
                    $pdo->query('SELECT pg_sleep(0.3)');
                    $group->done();
                });
            }

            $group->wait();
            $elapsed = ((float)hrtime(true) - (float)$startedAt) / 1e9;
        });

        self::assertLessThan(0.5, $elapsed, 'Два pg_sleep(0.3) шли последовательно: хук pdo_pgsql не работает');
    }
}
