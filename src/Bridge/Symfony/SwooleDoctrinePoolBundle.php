<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Bridge\Symfony;

use Override;
use SwooleDoctrinePool\Bridge\Symfony\DependencyInjection\Compiler\PoolConnectionPass;
use SwooleDoctrinePool\Bridge\Symfony\DependencyInjection\SwooleDoctrinePoolExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function dirname;

final class SwooleDoctrinePoolBundle extends AbstractBundle
{
    private ?SwooleDoctrinePoolExtension $poolExtension = null;

    #[Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->poolExtension ??= new SwooleDoctrinePoolExtension();
    }

    /** Класс лежит в src/Bridge/Symfony, а корень пакета — на три уровня выше. */
    #[Override]
    public function getPath(): string
    {
        return dirname(__DIR__, 3);
    }

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new PoolConnectionPass());
    }
}
