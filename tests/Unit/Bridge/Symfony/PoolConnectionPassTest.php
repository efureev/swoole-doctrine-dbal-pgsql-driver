<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Bridge\Symfony;

use Doctrine\DBAL\Connection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Bridge\Symfony\DependencyInjection\Compiler\PoolConnectionPass;
use SwooleDoctrinePool\Bridge\Symfony\DependencyInjection\SwooleDoctrinePoolExtension;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Driver\Driver;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(PoolConnectionPass::class)]
final class PoolConnectionPassTest extends TestCase
{
    public function testItInjectsDriverClassWrapperClassAndPoolOptions(): void
    {
        $container = $this->container(['default' => ['size' => 3]], ['default' => ['driver' => 'pdo_pgsql']]);

        (new PoolConnectionPass())->process($container);

        $params = $this->params($container, 'default');
        self::assertSame(Driver::class, $params['driverClass']);
        self::assertSame(CoroutineSafeConnection::class, $params['wrapperClass']);
        self::assertSame(['size' => 3], $params['driverOptions']['pool']);
        self::assertSame('pdo_pgsql', $params['driver'], 'driver остаётся: DriverManager при driverClass его игнорирует');
        self::assertSame(CoroutineSafeConnection::class, $container->getDefinition('doctrine.dbal.default_connection')->getClass());
    }

    public function testItKeepsIntegerKeyedPdoAttributes(): void
    {
        $container = $this->container(['default' => []], ['default' => ['driverOptions' => [PDO::ATTR_TIMEOUT => 5]]]);

        (new PoolConnectionPass())->process($container);

        self::assertSame(5, $this->params($container, 'default')['driverOptions'][PDO::ATTR_TIMEOUT]);
    }

    public function testItKeepsAUserWrapperClassThatExtendsCoroutineSafeConnection(): void
    {
        $subclass = get_class(new class ([], new Driver()) extends CoroutineSafeConnection {
        });
        $container = $this->container(['default' => []], ['default' => ['wrapperClass' => $subclass]]);

        (new PoolConnectionPass())->process($container);

        self::assertSame($subclass, $this->params($container, 'default')['wrapperClass']);
    }

    public function testItRejectsAForeignWrapperClass(): void
    {
        $container = $this->container(['default' => []], ['default' => ['wrapperClass' => Connection::class]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('wrapper_class');
        (new PoolConnectionPass())->process($container);
    }

    public function testItRejectsAForeignDriverClassButAcceptsItsOwn(): void
    {
        $own = $this->container(['default' => []], ['default' => ['driverClass' => Driver::class]]);
        (new PoolConnectionPass())->process($own);
        self::assertSame(Driver::class, $this->params($own, 'default')['driverClass']);

        $foreign = $this->container(['default' => []], ['default' => ['driverClass' => 'App\\MyDriver']]);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('driver_class');
        (new PoolConnectionPass())->process($foreign);
    }

    public function testItRejectsPoolOptionsPlacedInDoctrineOptions(): void
    {
        $container = $this->container(['default' => []], ['default' => ['driverOptions' => ['pool' => ['size' => 1]]]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('swoole_doctrine_pool');
        (new PoolConnectionPass())->process($container);
    }

    public function testItRejectsReplicas(): void
    {
        $container = $this->container(['default' => []], ['default' => ['replica' => ['r1' => ['host' => 'r']]]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('replica');
        (new PoolConnectionPass())->process($container);
    }

    public function testItNamesTheKnownConnectionsWhenTheNameIsUnknown(): void
    {
        $container = $this->container(['typo' => []], ['default' => [], 'other' => []]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('default, other');
        (new PoolConnectionPass())->process($container);
    }

    public function testItFailsWhenDoctrineBundleIsMissingButConnectionsAreConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(SwooleDoctrinePoolExtension::CONNECTIONS_PARAMETER, ['default' => []]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('DoctrineBundle');
        (new PoolConnectionPass())->process($container);
    }

    public function testItIsANoopWithoutPooledConnections(): void
    {
        $container = $this->container([], ['default' => ['driver' => 'pdo_pgsql']]);

        (new PoolConnectionPass())->process($container);

        self::assertArrayNotHasKey('driverClass', $this->params($container, 'default'));
        (new PoolConnectionPass())->process(new ContainerBuilder());
    }

    public function testItLeavesNonPooledConnectionsUntouched(): void
    {
        $container = $this->container(['default' => []], ['default' => [], 'other' => ['driver' => 'pdo_pgsql']]);

        (new PoolConnectionPass())->process($container);

        self::assertSame(['driver' => 'pdo_pgsql'], $this->params($container, 'other'));
    }

    public function testEnvPlaceholdersPassThroughUnchanged(): void
    {
        $container = $this->container(['default' => ['size' => '%env(int:POOL_SIZE)%']], ['default' => []]);

        (new PoolConnectionPass())->process($container);

        self::assertSame('%env(int:POOL_SIZE)%', $this->params($container, 'default')['driverOptions']['pool']['size']);
    }

    /**
     * @param array<string, array<string, mixed>> $pooled
     * @param array<string, array<string, mixed>> $doctrine
     */
    private function container(array $pooled, array $doctrine): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(SwooleDoctrinePoolExtension::CONNECTIONS_PARAMETER, $pooled);
        $container->setDefinition('doctrine.dbal.connection', (new Definition(Connection::class))->setAbstract(true));

        $ids = [];

        foreach ($doctrine as $name => $params) {
            $id = 'doctrine.dbal.' . $name . '_connection';
            $ids[$name] = $id;
            $container->setDefinition($id, (new ChildDefinition('doctrine.dbal.connection'))->setArguments([
                $params,
                new Reference($id . '.configuration'),
                [],
            ]));
        }

        $container->setParameter('doctrine.connections', $ids);

        return $container;
    }

    /** @return array<string, mixed> */
    private function params(ContainerBuilder $container, string $name): array
    {
        /** @var array<string, mixed> $params */
        $params = $container->getDefinition('doctrine.dbal.' . $name . '_connection')->getArgument(0);

        return $params;
    }
}
