# Changelog

Формат — [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/), версии — [SemVer](https://semver.org/lang/ru/).

## 3.0.0 — 2026-09-23

Полностью новый пакет: v2 строилась на `Swoole\Coroutine\PostgreSQL`, которого в Swoole 6 нет.
Миграция — [UPGRADE-3.0.md](UPGRADE-3.0.md).

### Added

- Пул соединений на `Swoole\Coroutine\Channel`-семафоре: токен берётся до подключения, поэтому больше
  `size` соединений не открывается ни при какой конкуренции; idle — LIFO-стек, обслуживание никогда не теряет
  соединения.
- `CoroutineSafeConnection` — обёртка DBAL, у которой уровень вложенности транзакции, rollback-only и уровень
  изоляции живут на lease корутины. Стандартная `Doctrine\DBAL\Connection` хранит их в одном поле для всех
  корутин воркера: `SAVEPOINT` уходил на чужое соединение, `commit()` одной корутины сбрасывал уровень другой.
- При возврате в пул всегда `ROLLBACK` незавершённой транзакции (в том числе открытой сырым `BEGIN`),
  затем `DISCARD ALL` (`reset_on_release: discard`, по умолчанию) или ничего (`rollback_only`).
- `LeaseAwareStatement`/`LeaseAwareResult`: statement чужой корутины или после release —
  `LeaseViolationException`, а не запрос в чужую транзакцию.
- `PoolExceptionConverter`: SQLSTATE `08xxx`/`57P0x` и характерные сообщения поднимаются до
  `Doctrine\DBAL\Exception\ConnectionLost`; DBAL сам освобождает lease, соединение уничтожается.
  `PoolExhaustedException` (наследует `ConnectionException`) при исчерпании.
- Пул на каждый DSN (`PoolRegistry`), lazy-создание в воркере, отбрасывание пулов, унаследованных после fork.
- Прямой режим вне корутины: CLI и phpunit работают без пула.
- `validate_idle_after`, `max_lifetime`, `idle_timeout`, `max_uses`, `min_idle` с прогревом, `acquire_timeout`.
- События PSR-14: `PoolCreated`, `PoolClosed`, `ConnectionOpened`, `ConnectionClosed`, `LeaseAcquired`,
  `LeaseReleased`, `RolledBackOnRelease`, `AcquireTimedOut`; `PoolStats` и `PoolStatsProviderInterface`.
- Symfony-бандл с собственным деревом `swoole_doctrine_pool`: compiler pass подставляет `driverClass`,
  `wrapperClass` и опции пула в соединения DoctrineBundle; middleware гарантирует пул при `url:`;
  слушатели `kernel.terminate`/`console.terminate`, `swoole.worker_start` (прогрев) и
  `swoole.worker_stop`/`worker_exit`/`before_shutdown` (закрытие) — по строковым именам, без зависимости от конкретного рантайма.
- Тесты: 140+ юнит-тестов без ext-swoole (фейки), интеграционные тесты в docker на реальных Swoole 6.2.3
  и Postgres 17 — каждая гарантия безопасности данных покрыта тестом. CI на GitHub Actions.

### Changed

- **Ломающее изменение:** транспорт — `pdo_pgsql` под `SWOOLE_HOOK_PDO_PGSQL` (Swoole ≥ 6.2, сборка
  с `--enable-swoole-pgsql`) вместо `Swoole\Coroutine\PostgreSQL`.
- **Ломающее изменение:** минимумы PHP ≥ 8.5, doctrine/dbal ^4.4, Symfony ^8.1 (только для бандла), DoctrineBundle ^3.
- **Ломающее изменение:** namespace `Swoole\Packages\Doctrine\DBAL\*` → `SwooleDoctrinePool\*`; бандл
  `SwooleDoctrineDbalPoolBundle` → `SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle`.
- **Ломающее изменение:** конфигурация пула переехала из `doctrine.dbal.connections.*.options` в
  `swoole_doctrine_pool.connections.<имя>`; ключи переименованы (см. UPGRADE, шаг 5); `driver_class`
  в `doctrine.yaml` больше не указывается.
- **Ломающее изменение:** события `PgSQL\Events\Pool*` заменены на `SwooleDoctrinePool\Event\*` (PSR-14).
- Lease держится до конца запроса (terminate/close/defer), а не до конца SQL-запроса — ради `lastInsertId()`
  и транзакций на одной сессии.

### Removed

- `Cursor`, `SQLParserUtils`, `DriverKeeperMiddleware`, `DriverMiddleware`, `ConnectionClosePass`,
  `ConnectionCloseSubscriber`, `ConnectionPoolFactory(Interface)`, `ConnectionPoolKeeper`, `Scaler`,
  `ConnectionDirect`, `ConnectionStats`, `example/`.
- Опции `retryMaxAttempts`/`retryDelay`: пул ждёт слот до `acquire_timeout` вместо повторов.

### Замечания по эксплуатации под Swoole-рантаймом

- `worker_exit` (reload_async, `max_request`) переводит пулы в drain, а не закрывает: запросы в полёте
  дообслуживаются; полное закрытие — на `worker_stop`.
- `console.terminate` закрывает пулы: команда, выполняемая внутри `Coroutine\run()`, иначе не завершилась бы из-за таймера.
- Таймер обслуживания по умолчанию выключен (`maintenance_interval: 0`), чистка простаивающих — ленивая при
  возврате соединения; включайте таймер только в HTTP-воркерах.
- Отсутствие хука `SWOOLE_HOOK_PDO_PGSQL` в корутине — warning, а не исключение: консоль и master живут без хуков.
- Поддержка replicas (`primary`/`replica`) через пул — не поддерживается в 3.0.
