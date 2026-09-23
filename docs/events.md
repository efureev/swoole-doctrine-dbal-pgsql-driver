**English** | [Русский](events.ru.md)

# Events

Immutable `SwooleDoctrinePool\Event\*` objects, all implementing `PoolEvent::poolLabel()`. Dispatched through
`Psr\EventDispatcher\EventDispatcherInterface` (Symfony's dispatcher implements it — subscribe with
`#[AsEventListener]` by event class). Without a dispatcher no event is even constructed. A listener exception
is logged and does not interrupt the release.

| Event | Fields | When |
|---|---|---|
| `PoolCreated` | `poolLabel`, `config: PoolConfig` | the pool was created (first query in the worker) |
| `PoolClosed` | `poolLabel`, `stats: PoolStats` | `closeAll()` finished |
| `ConnectionOpened` | `poolLabel`, `connectionId`, `openSeconds` | a physical connection was opened |
| `ConnectionClosed` | `poolLabel`, `connectionId`, `reason: CloseReason`, `ageSeconds`, `uses` | closed; `reason`: `max_lifetime`, `max_uses`, `idle_timeout`, `broken`, `validation_failed`, `rollback_failed`, `reset_failed`, `pool_closed`, `drained` |
| `LeaseAcquired` | `poolLabel`, `cid`, `connectionId`, `waitedSeconds`, `fromIdle` | a coroutine received a connection |
| `LeaseReleased` | `poolLabel`, `cid`, `connectionId`, `heldSeconds`, `rolledBack`, `viaDefer` | the connection went back to idle |
| `RolledBackOnRelease` | `poolLabel`, `cid`, `connectionId`, `viaDefer` | there was an open transaction on release — rolled back. Almost always a handler bug |
| `AcquireTimedOut` | `poolLabel`, `cid`, `waitedSeconds`, `stats` | `acquire_timeout` expired |

`poolLabel` is `user@host:port/dbname`, without the password.

A subscriber example (Symfony):

```php
#[AsEventListener]
final class PoolAudit
{
    public function __invoke(RolledBackOnRelease $event): void
    {
        $this->logger->warning('transaction left open by a handler', ['pool' => $event->poolLabel, 'cid' => $event->cid]);
    }
}
```
