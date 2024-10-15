<?php

declare(strict_types=1);

namespace Swoole\Packages\Doctrine\DBAL\Symfony;

use Doctrine\DBAL\Connection;
use Swoole\Coroutine as Co;
use Swoole\Packages\Doctrine\DBAL\PgSQL\ConnectionDirect;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ConnectionCloseSubscriber implements EventSubscriberInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onTerminate',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onTerminate(ConsoleTerminateEvent|TerminateEvent $event): void
    {
        if ($event instanceof TerminateEvent && !$event->isMainRequest()) {
            return;
        }

        $context = Co::getContext(Co::getCid());
        if ($context === null || !isset($context[ConnectionDirect::class])) {
            return;
        }

        unset($context[ConnectionDirect::class]);
        $this->connection->close();
    }
}
