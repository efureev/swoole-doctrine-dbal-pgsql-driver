<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];
    public ?\Throwable $throw = null;

    #[Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, static fn(object $e): bool => $e instanceof $class));
    }
}
