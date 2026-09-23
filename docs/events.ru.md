[English](events.md) | **Русский**

# События

Неизменяемые объекты `SwooleDoctrinePool\Event\*`, все реализуют `PoolEvent::poolLabel()`. Отправляются
через `Psr\EventDispatcher\EventDispatcherInterface` (диспетчер Symfony его реализует — подписка
`#[AsEventListener]` по классу события). Без диспетчера события не создаются вовсе. Исключение слушателя
логируется и не прерывает release.

| Событие | Поля | Когда |
|---|---|---|
| `PoolCreated` | `poolLabel`, `config: PoolConfig` | пул создан (первый запрос в воркере) |
| `PoolClosed` | `poolLabel`, `stats: PoolStats` | `closeAll()` завершён |
| `ConnectionOpened` | `poolLabel`, `connectionId`, `openSeconds` | открыто физическое соединение |
| `ConnectionClosed` | `poolLabel`, `connectionId`, `reason: CloseReason`, `ageSeconds`, `uses` | закрыто; `reason`: `max_lifetime`, `max_uses`, `idle_timeout`, `broken`, `validation_failed`, `rollback_failed`, `reset_failed`, `pool_closed`, `drained` |
| `LeaseAcquired` | `poolLabel`, `cid`, `connectionId`, `waitedSeconds`, `fromIdle` | корутина получила соединение |
| `LeaseReleased` | `poolLabel`, `cid`, `connectionId`, `heldSeconds`, `rolledBack`, `viaDefer` | соединение вернулось в idle |
| `RolledBackOnRelease` | `poolLabel`, `cid`, `connectionId`, `viaDefer` | при возврате была открытая транзакция — откачена. Почти всегда баг обработчика |
| `AcquireTimedOut` | `poolLabel`, `cid`, `waitedSeconds`, `stats` | `acquire_timeout` истёк |

`poolLabel` — `user@host:port/dbname`, без пароля.

Пример подписчика (Symfony):

```php
#[AsEventListener]
final class PoolAudit
{
    public function __invoke(RolledBackOnRelease $event): void
    {
        $this->logger->warning('транзакция не закрыта обработчиком', ['pool' => $event->poolLabel, 'cid' => $event->cid]);
    }
}
```
