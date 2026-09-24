<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;

/**
 * Статическое состояние переживает fork и утекает между воркерами (а в SWOOLE_THREAD — между потоками):
 * в пакете его быть не должно, вся память — в объектах реестра.
 */
#[CoversNothing]
final class NoStaticStateTest extends TestCase
{
    public function testNoClassInSrcDeclaresAStaticProperty(): void
    {
        $offenders = [];

        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $class) {
                    $offenders[] = $class . '::$' . $property->getName();
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /** @return list<class-string> */
    private function classes(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $classes = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            /** @var class-string $class */
            $class = 'SwooleDoctrinePool\\' . str_replace('/', '\\', $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        self::assertGreaterThan(20, count($classes));

        return $classes;
    }
}
