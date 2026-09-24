[English](architecture.md) | **Русский**

# Архитектура

## Слои

```
Doctrine\DBAL\Connection ── CoroutineSafeConnection        приложение / ORM
   │  состояние транзакции → на lease текущей корутины
   └─ VirtualConnection (Driver\Connection, без состояния)
        │  на каждом вызове: lease текущей корутины из Coroutine::getContext()
        └─ Lease ── PhysicalConnection (PDO)  ←  Pool данного DSN  ←  PoolRegistry (один на воркер)
```

| Компонент | Ответственность |
|---|---|
| `Driver\Driver` | DBAL-драйвер (`driverClass`): `connect()` дёшев и без сети, возвращает `VirtualConnection`; платформа и конвертер исключений — от внутреннего Postgres-драйвера |
| `Driver\PgsqlDriver` | открывает физическое PDO: DSN из параметров DBAL, `ERRMODE_EXCEPTION`, отключённые серверные prepared statements, `SET NAMES`. Без штатного `Driver\PDO\PgSQL\Driver` DBAL — тот на PHP ≥ 8.4 требует класс `Pdo\Pgsql`, которого во встроенном в Swoole `pdo_pgsql` нет |
| `Driver\PoolDriverMiddleware` | `Doctrine\DBAL\Driver\Middleware`: переводит любой Postgres-драйвер на пул, подставляет общий `PoolRegistry`. Должен быть самым внутренним |
| `Driver\VirtualConnection` | driver-level соединение на всю жизнь обёртки; маршрутизирует вызов в lease корутины, вне корутины — в одно прямое PDO |
| `Driver\LeaseAwareStatement` / `LeaseAwareResult` | проверка владения: statement из чужой корутины или после release → `LeaseViolationException`; результат после release читать нельзя |
| `Driver\PoolExceptionConverter` | поверх штатного PostgreSQL-конвертера: `08xxx`/`57P0x`/характерные сообщения → `ConnectionLost`; исчерпание пула → `PoolExhaustedException` |
| `DBAL\CoroutineSafeConnection` | обёртка (`wrapperClass`): `beginTransaction/commit/rollBack/…` на `TransactionState` lease текущей корутины; `close()` = release lease |
| `Lease\Lease`, `LeaseSet`, `LeaseBinder` | владение соединением корутиной: контекст корутины, `defer`, идемпотентный release |
| `Pool\Pool` | семафор + idle-стек + учёт; acquire/release/maintain/drain/close |
| `Pool\Slots` (`ChannelSlots`) | счётный семафор на `Swoole\Coroutine\Channel`: FIFO-ожидание с таймаутом |
| `Pool\PoolRegistry` | пулы воркера по DSN, общий `LeaseBinder`, `closeAll()`, статистика, защита от fork |
| `Config\PoolConfig` | настройки с проверкой и приведением строк из env |
| `Event\*`, `Stats\*` | PSR-14 события, снимки состояния |
| `Bridge\Symfony\*` | бандл: конфигурация, compiler pass, слушатели |

## Lease

- Берётся **лениво** при первом запросе корутины, привязан к её `cid`.
- Живёт до **release**: `kernel.terminate`/`console.terminate` (Symfony), `$connection->close()`,
  `PoolRegistry::releaseCurrentCoroutine()` или — safety-net — `Coroutine::defer` при завершении корутины.
- Не statement-scoped намеренно: `lastInsertId()` после `INSERT` и вся транзакция обязаны идти на одной сессии.
- Дочерняя корутина имеет свой контекст → свой lease → **вне транзакции родителя**.
- Одна корутина может держать по lease в каждом пуле (несколько БД).

## Acquire

1. Взять токен семафора (`Channel::pop(acquire_timeout)`). Токен = право держать соединение; берётся **до**
   подключения, поэтому больше `size` соединений не откроется никогда, сколько бы корутин ни пришло
   одновременно. Таймаут → `AcquireTimeoutException` → на уровне обёртки `PoolExhaustedException`.
2. Снять соединение с вершины idle-стека (LIFO — самое тёплое). Просроченное по `max_lifetime`/`max_uses`
   закрывается; простоявшее дольше `validate_idle_after` проверяется `SELECT 1`, мёртвое закрывается — и цикл.
3. Idle пуст → открыть новое соединение через `PgsqlDriver`.

## Release (никогда не бросает)

1. Соединение помечено сломанным → уничтожить.
2. `PDO::inTransaction()` (серверное состояние — видит и сырой `BEGIN`) → `ROLLBACK`, warning в лог,
   событие `RolledBackOnRelease`. Ошибка отката → уничтожить.
3. Пул закрывается / соединение просрочено → уничтожить.
4. `reset_on_release: discard` → `DISCARD ALL`. Ошибка → уничтожить.
5. В idle; токен обратно в семафор — это будит ожидающего acquire.
6. Ленивая чистка со дна idle-стека: просроченные по `max_lifetime`/`max_uses` закрываются, простоявшие
   дольше `idle_timeout` — пока idle больше `min_idle`.

Пул **никогда не коммитит** за приложение.

## Учёт

Токены считают `inUse + connecting`; простаивающее соединение токена не держит. Инварианты, которые
проверяют тесты после любой последовательности операций:

- `inUse + connecting + свободных токенов == size`;
- `idle + inUse + connecting ≤ size`.

Ничего не зависит от GC (`WeakMap` нет), idle никогда не дренируется через канал — терять соединения негде.

## Обслуживание и жизненный цикл

- `maintenance_interval: 0` (по умолчанию) — таймера нет; единственная чистка — ленивая при release. В полной
  тишине ничего не закрывается, и это безвредно: соединение, простоявшее дольше `validate_idle_after`, перед
  выдачей проверяется. Положительный интервал добавляет `Swoole\Timer`: чистка и в тишине, поддержание `min_idle`.
- `drain()` — воркер уходит, но ещё дообслуживает запросы (`worker_exit` при `reload_async`, `max_request`,
  перезапуск по памяти): таймер снят, простаивающие закрыты, возвращаемые закрываются вместо возврата в стек,
  **новые lease по-прежнему выдаются**.
- `close()` — полное закрытие (`worker_stop`, `console.terminate`): после drain ждёт занятые до `drainTimeout`,
  затем закрывает семафор — ожидающие получают `PoolClosedException`.

## Потоки выполнения

**Первый запрос в корутине**

```
executeQuery → CoroutineSafeConnection::connect() → VirtualConnection::prepare()
  → LeaseBinder::current(hash) = null → Pool::acquire()
      → Slots::acquire(timeout) [токен] → idle пуст → PgsqlDriver::connect() [yield]
  → new Lease(ownerCid) → Coroutine::defer(release) → Context[leases][hash] = lease
  → LeaseAwareStatement::execute() → LeaseAwareResult
второй запрос той же корутины: Context-lookup, пул не трогается
```

**Транзакция с вложением**

```
beginTransaction  level 0→1: PDO::beginTransaction на lease
beginTransaction  level 1→2: SAVEPOINT DOCTRINE_2 (тот же lease)
rollBack          level 2→1: ROLLBACK TO SAVEPOINT DOCTRINE_2
commit            level 1→0: PDO::commit
```

**Корутина завершилась с открытой транзакцией**

```
defer → Lease::release(viaDefer) → Pool::release
  → inTransaction() → ROLLBACK → warning + RolledBackOnRelease → DISCARD ALL → idle
```

**Потеря соединения во время запроса**

```
PDOException 08006 → LeaseAwareStatement помечает lease broken → DBAL конвертирует
  → PoolExceptionConverter → ConnectionLost → DBAL зовёт close() обёртки
  → Lease::release() → Pool::destroy(Broken) → ConnectionClosed(broken)
исключение уходит в приложение; следующий запрос берёт новое соединение
```

**Исчерпание**

```
Pool::acquire → Slots::acquire(5.0) ждёт (FIFO) → TIMEOUT → AcquireTimedOut(stats)
  → AcquireTimeoutException → PoolExhaustedException (ConnectionException)
lease не привязан, defer не зарегистрирован, токен не потрачен
```

**Рецикл и остановка воркера**

```
swoole.worker_exit  (повторяется, пока loop не пуст) → PoolRegistry::drainAll() → Pool::drain
  → таймер снят → idle закрыты → запросы в полёте продолжают брать соединения; release уничтожает (Drained)
swoole.worker_stop  → PoolRegistry::closeAll(5.0) → Pool::close
  → ждать inUse == 0 → Slots::close() → ожидающие получают PoolClosedException → PoolClosed(stats)
console.terminate   → releaseCurrentCoroutine() + closeAll() (живой таймер не дал бы Coroutine\run() вернуться)
```

## Гарантии безопасности данных

| Отказ | Как исключён |
|---|---|
| Одно PDO у двух корутин | владелец фиксируется при выдаче; дочерние корутины — свой контекст, свой lease |
| Statement/Result после того, как соединение ушло другой корутине | `LeaseAwareStatement::execute` проверяет release и cid; `LeaseAwareResult` — release |
| Неявный commit | нигде: release только откатывает; `close()` откатывает; `closeAll()` уничтожает занятые после их собственного отката |
| Состояние транзакции видно другой корутине | уровень, rollback-only, изоляция — на lease; `isTransactionActive()` отвечает за текущую корутину |
| Обёртка думает, что транзакция открыта на исчезнувшем соединении | lease освобождён ⇒ состояния нет ⇒ уровень 0; post-actions commit/rollBack проверяют, что состояние то же |
| Мёртвое соединение вернулось в пул | `markBroken` при ошибке класса «потеря»; любая ошибка на пути возврата → уничтожение |
| Повтор неидемпотентной операции после потери соединения | автоматических повторов нет |
| Утечка состояния сессии между запросами | `DISCARD ALL` по умолчанию |
| Больше `size` соединений под наплывом | токен до подключения |
| Потеря соединений из-за ошибок учёта | нет GC-зависимых структур; инварианты проверяются тестами |
| Забытый release при исключении | `Coroutine::defer` регистрируется при выдаче |
| Пул из master-процесса после fork | реестр отбрасывает пулы чужого pid с warning, не закрывая разделённые сокеты |
| PDO без хука блокирует воркер | warning один раз на реестр при создании пула в корутине без `SWOOLE_HOOK_PDO_PGSQL` (потеря конкурентности, не данных; консоль и master легитимно живут без хуков) |
| `lastInsertId()` читает `lastval` чужой сессии | lease на весь запрос |
| Запросы в полёте теряют БД при рецикле воркера | `worker_exit` только drain; новые lease выдаются до `worker_stop` |

## Прямой режим

Вне корутины (`Coroutine::getCid() === -1`): одно прямое PDO без пула, таймеров и defer; состояние транзакции
в обёртке; `close()` откатывает незавершённую транзакцию и закрывает PDO. Ограничение Swoole: при глобально
включённых хуках PDO вне корутины запрещён — задавайте хуки через `Coroutine::set(['hook_flags' => …])`,
если скрипт работает и снаружи `Coroutine\run()`.

## Fork и потоки

Пулы создаются лениво в воркере. Статических свойств в `src/` нет (это проверяет тест): всё состояние
в `PoolRegistry`, в SWOOLE_THREAD изоляция между потоками получается сама собой.

## Производительность

- Горячий путь корутины с lease: чтение контекста + две проверки; пул не участвует.
- LIFO idle держит соединения тёплыми: `SELECT 1` почти не выполняется под ровной нагрузкой.
- `rollback_only` убирает один round-trip на release; `ROLLBACK` выполняется только при открытой транзакции
  (по `PQtransactionStatus`, без запроса).
- Версия сервера кэшируется из `PDO::ATTR_SERVER_VERSION`; с `server_version` в конфигурации платформа
  строится без обращения к БД.
- События строятся только при наличии диспетчера.
