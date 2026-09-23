<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Tests\Integration\Support\CoroutineTestCase;
use SwooleDoctrinePool\Tests\Unit\Bridge\Symfony\Fixture\TestKernel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\Event;


use function Swoole\Coroutine\run;

/**
 * Полный контур Symfony: DoctrineBundle с url (driverClass выброшен), middleware подставляет пул,
 * kernel.terminate возвращает соединение, swoole.worker_stop закрывает пулы.
 */
#[CoversNothing]
final class SymfonyLifecycleTest extends CoroutineTestCase
{
    private ?TestKernel $kernel = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->kernel !== null) {
            $dir = $this->kernel->getProjectDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove($dir);
        }

        parent::tearDown();
    }

    public function testTheBundleWiresThePoolThroughTheUrlPathAndListenersDriveTheLifecycle(): void
    {
        $kernel = $this->kernel = new TestKernel(__DIR__ . '/Fixture/config.yaml');
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var PoolRegistry $registry */
        $registry = $container->get('test.pool_registry');
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        $error = null;

        run(static function () use ($registry, $doctrine, $dispatcher, $kernel, &$error): void {
            try {
                $connection = $doctrine->getConnection();
                self::assertInstanceOf(CoroutineSafeConnection::class, $connection);

                self::assertSame(1, (int)$connection->fetchOne('SELECT 1'));
                self::assertSame(1, $registry->count(), 'Пул создан через middleware, хотя url выбросил driverClass');
                self::assertSame(1, self::pool($registry)->stats()->inUse);

                $dispatcher->dispatch(
                    new TerminateEvent($kernel, Request::create('/'), new Response()),
                    KernelEvents::TERMINATE,
                );
                self::assertSame(0, self::pool($registry)->stats()->inUse, 'kernel.terminate вернул соединение');
                self::assertSame(1, self::pool($registry)->stats()->idle);

                $dispatcher->dispatch(new Event(), 'swoole.worker_stop');
                self::assertSame(0, $registry->count(), 'swoole.worker_stop закрыл пулы');
            } catch (\Throwable $e) {
                $error = $e;
            } finally {
                $registry->closeAll(1.0);
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }
}
