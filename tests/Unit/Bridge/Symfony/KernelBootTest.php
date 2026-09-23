<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Bridge\Symfony;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\PoolDriverMiddleware;
use SwooleDoctrinePool\Tests\Unit\Bridge\Symfony\Fixture\TestKernel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Настоящий контейнер FrameworkBundle + DoctrineBundle: проверяет, что compiler pass и middleware
 * доходят до реального соединения без обращения к БД.
 */
#[CoversClass(SwooleDoctrinePoolBundle::class)]
final class KernelBootTest extends TestCase
{
    private ?TestKernel $kernel = null;

    #[\Override]
    protected function setUp(): void
    {
        $_SERVER['POOL_SIZE'] = '7';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->kernel !== null) {
            $dir = $this->kernel->getProjectDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove($dir);
        }

        unset($_SERVER['POOL_SIZE']);
    }

    public function testThePooledConnectionIsACoroutineSafeConnectionWithResolvedOptions(): void
    {
        $connection = $this->boot('config.yaml')->getConnection('default');

        self::assertInstanceOf(CoroutineSafeConnection::class, $connection);
        $params = $connection->getParams();
        self::assertSame(7, $params['driverOptions']['pool']['size'], 'env-плейсхолдер разрешён в int');
        self::assertSame('rollback_only', $params['driverOptions']['pool']['reset_on_release']);
    }

    public function testTheOtherConnectionIsLeftOnPlainDoctrine(): void
    {
        $connection = $this->boot('config.yaml')->getConnection('other');

        self::assertNotInstanceOf(CoroutineSafeConnection::class, $connection);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertArrayNotHasKey('pool', $connection->getParams()['driverOptions'] ?? []);
    }

    public function testThePoolMiddlewareIsTheInnermostOne(): void
    {
        $this->boot('config.yaml');
        $middlewares = $this->kernel?->getContainer()->getParameter(TestKernel::MIDDLEWARES_PARAMETER);

        self::assertIsArray($middlewares);
        self::assertNotEmpty($middlewares);
        self::assertStringStartsWith(PoolDriverMiddleware::class, (string)$middlewares[0], 'Первый оборачивает первым — он самый внутренний');
    }

    public function testAnUnknownConnectionNameFailsContainerCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('typo');

        $this->boot('config-unknown.yaml');
    }

    private function boot(string $config): ManagerRegistry
    {
        $this->kernel = new TestKernel(__DIR__ . '/Fixture/' . $config);
        $this->kernel->boot();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->kernel->getContainer()->get('doctrine');

        return $doctrine;
    }
}
