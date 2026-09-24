<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Bridge\Symfony\Fixture;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;

use function array_map;
use function getmypid;
use function sys_get_temp_dir;

/**
 * Ядро с FrameworkBundle + DoctrineBundle + бандлом пакета. Порядок middleware соединения
 * записывается в параметр контейнера, потому что после компиляции definitions недоступны.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string MIDDLEWARES_PARAMETER = 'test.middlewares.default';

    public function __construct(private readonly string $config)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SwooleDoctrinePoolBundle();
    }

    /** Не корень пакета: FrameworkBundle пишет туда config/reference.php. */
    public function getProjectDir(): string
    {
        return sys_get_temp_dir() . '/swoole-doctrine-pool/' . (int)getmypid() . '/' . md5($this->config);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $id = 'doctrine.dbal.default_connection.configuration';

                if (!$container->hasDefinition($id)) {
                    return;
                }

                foreach ($container->getDefinition($id)->getMethodCalls() as [$method, $arguments]) {
                    if ($method === 'setMiddlewares') {
                        $container->setParameter(TestKernel::MIDDLEWARES_PARAMETER, array_map(
                            static fn(Reference $reference): string => (string)$reference,
                            $arguments[0],
                        ));
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        // Сущности интеграционных тестов лежат в репозитории, а project_dir у этого ядра — во временной папке.
        $container->parameters()->set('test.fixtures_dir', dirname(__DIR__, 4) . '/Integration/Fixture');
        $container->import($this->config);
    }
}
