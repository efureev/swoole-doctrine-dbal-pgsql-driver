[English](use-cases.md) | **Русский**

# Сценарии использования

Каждый сценарий — что происходит с соединением, что нужно сделать в коде и на что смотреть.
Термины: *lease* — соединение, выданное корутине до release; *release* — возврат в пул.

## 1. HTTP-обработчик под Swoole-рантаймом

Ничего делать не нужно. Контроллер использует `Doctrine\DBAL\Connection` / `EntityManagerInterface` как обычно:

```php
final class OrderController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/orders/{id}')]
    public function show(int $id): JsonResponse
    {
        $order = $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id]);

        return new JsonResponse($order ?: null, $order ? 200 : 404);
    }
}
```

Под капотом: первый `fetchAssociative` в корутине запроса берёт lease из пула; все последующие запросы
этого HTTP-запроса идут по нему; `kernel.terminate` (после отправки ответа) возвращает соединение;
если обработчик упал до terminate — вернёт `Coroutine::defer` при завершении корутины.

Что смотреть: `PoolStats::inUse` под нагрузкой ≈ число одновременных запросов, `acquireTimeoutsTotal` = 0.

## 2. Транзакция

```php
$this->db->transactional(function (Connection $db) use ($from, $to, $amount): void {
    $db->executeStatement('UPDATE accounts SET balance = balance - ? WHERE id = ?', [$amount, $from]);
    $db->executeStatement('UPDATE accounts SET balance = balance + ? WHERE id = ?', [$amount, $to]);
});
```

- Оба `UPDATE` — на одном соединении в одной транзакции; соседние корутины её не видят и не могут
  сломать: уровень вложенности живёт на lease этой корутины.
- Исключение внутри → `rollBack()` и проброс. Исключение *вне* `transactional()` с открытой транзакцией
  (например, `beginTransaction()` без `try/finally`) → при release пул сделает `ROLLBACK`, запишет warning
  и отправит `RolledBackOnRelease`. Данные не потеряются, но это баг обработчика — следите за
  `rollbacksOnReleaseTotal`.
- Вложенные `beginTransaction()` → `SAVEPOINT`, как в DBAL.
- `ConnectionLost` посреди транзакции → транзакция откачена сервером, соединение уничтожено, следующий
  запрос идёт по новому. Повторять операцию или нет — решает приложение (см. сценарий 10).

## 3. ORM: EntityManager на корутину

Пакет делает coroutine-safe DBAL. `EntityManager` хранит UnitOfWork — под конкурентными корутинами один общий
`EntityManager` смешает сущности разных запросов. Варианты:

**A. Синхронный код в обработчике (по умолчанию так и есть).** Один HTTP-запрос — одна корутина — весь код
обработчика последователен; общий `EntityManager` из контейнера работает, потому что между запросами
он в разных состояниях не оказывается *одновременно*… пока внутри обработчика нет `go()`. Но `flush()` одного
запроса может увидеть сущности другого, если запросы чередуются на I/O. Для безопасности:

**B. EntityManager на корутину.** Через фабрику с общей конфигурацией:

```php
final class CoroutineEntityManagerFactory
{
    public function __construct(
        private readonly Connection $connection,       // CoroutineSafeConnection из контейнера
        private readonly Configuration $ormConfig,     // doctrine.orm.default_configuration
    ) {}

    public function create(): EntityManagerInterface
    {
        return new EntityManager($this->connection, $this->ormConfig);
    }
}
```

```php
$em = $this->factory->create();          // в начале обработчика или задачи
$em->wrapInTransaction(static function () use ($em, $order): void {
    $em->persist($order);
});
$em->close();
```

`IDENTITY`-идентификаторы работают: `lastInsertId()` уходит на ту же сессию, что и `INSERT` — это
проверяет `OrmTest` (20 конкурентных flush, все id уникальны).

## 4. Параллельные запросы внутри одного HTTP-запроса

```php
$profile = $stats = null;
$group = new WaitGroup();

$group->add();
Coroutine::create(function () use ($userId, $group, &$profile): void {
    $profile = $this->db->fetchAssociative('SELECT * FROM profiles WHERE user_id = ?', [$userId]);
    $group->done();
});

$group->add();
Coroutine::create(function () use ($userId, $group, &$stats): void {
    $stats = $this->db->fetchAssociative('SELECT count(*) AS n FROM events WHERE user_id = ?', [$userId]);
    $group->done();
});

$group->wait();
```

- Каждая дочерняя корутина получает **своё** соединение (и освобождает его при завершении через defer).
  Один запрос занимает `1 + N` соединений — учитывайте в `size`.
- Дочерняя корутина **вне транзакции родителя**: незакоммиченные изменения родителя ей не видны.
  Если нужно читать внутри транзакции — читайте последовательно в родителе.
- `Statement`, подготовленный родителем, из дочерней корутины выполнить нельзя (`LeaseViolationException`) —
  готовьте в той корутине, где выполняете.

## 5. Фоновые демоны и тикающие задачи

Демон под Swoole-рантаймом — долгоживущая корутина. Lease по умолчанию живёт до её завершения, то есть
навсегда: соединение выпадает из пула и никогда не сбрасывается. Освобождайте после каждой итерации:

```php
final class OutboxRelay // рантайм тикает его внутри корутины
{
    protected function tick(): void
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM outbox WHERE sent_at IS NULL LIMIT 100');
            // ... отправить, пометить
        } finally {
            $this->db->close();      // вернуть соединение до следующего тика
        }
    }
}
```

`close()` в корутине — это именно release lease; обёртка остаётся рабочей, следующий запрос возьмёт
соединение заново. То же для `Swoole\Timer::tick`-колбэков и обработчиков task-воркеров.

Демон, чей `run()` возвращается ради перезапуска процесса: при `maintenance_interval: 0`
(по умолчанию) у пула нет таймера и процесс ничто не держит; если таймер включён — перед возвратом вызовите
`PoolRegistry::closeAll()` (или `Timer::clearAll()`).

## 6. Консольные команды и миграции

- `bin/console` под рантаймом, выполняющим команду внутри корутины:
  пул работает, `console.terminate` возвращает соединение.
- Обычный `bin/console` без Swoole-рантайма или без `Coroutine\run()` — прямой режим: одно PDO без пула,
  таймеров и `defer`; `doctrine:migrations:migrate`, `doctrine:schema:*` работают как всегда.
- Консольное приложение, гоняющее команды внутри `Coroutine\run()` **без хуков** (частый паттерн): пул
  работает, PDO просто блокирует единственную корутину — безвредно; warning пишется один раз. На
  `console.terminate` бандл закрывает пулы, чтобы команда могла завершиться.
- Долгая команда с миллионами строк в прямом режиме держит одно соединение — как и без пула.

Отдельное «прямое» соединение в `doctrine.yaml` для консоли больше не нужно.

## 7. Несколько баз данных

```yaml
doctrine:
  dbal:
    connections:
      default:   { url: '%env(DATABASE_URL)%',   server_version: '17', idle_connection_ttl: 0 }
      analytics: { url: '%env(ANALYTICS_URL)%',  server_version: '17', idle_connection_ttl: 0 }
      legacy:    { url: '%env(LEGACY_URL)%',     server_version: '13' }

swoole_doctrine_pool:
  connections:
    default:   { size: 20 }
    analytics: { size: 4, acquire_timeout: 10.0 }   # тяжёлые запросы, ждать дольше
    # legacy — не в списке: остаётся на обычном pdo_pgsql
```

Пул — на каждый DSN (`host/port/dbname/user/password/...`); два соединения с одинаковыми параметрами делят
один пул. Корутина может держать по одному lease в каждом пуле одновременно; транзакции разных БД
независимы (двухфазного коммита нет).

## 8. Graceful shutdown и reload

С рантаймом, который диспатчит события `swoole.*`, это две фазы. `swoole.worker_exit` (приходит при `reload_async` — по `max_request`,
перезапуску по памяти или reload — пока старый воркер ещё дообслуживает запросы в полёте) → `drainAll()`:
таймеры сняты, idle закрыты, возвращаемые закрываются, **новые lease по-прежнему выдаются**, так что запрос,
ещё не тронувший БД, отработает. `swoole.worker_stop` (корутин больше нет) → `closeAll(5.0)`: ждёт занятые,
затем будит ожидающих `PoolClosedException`. При `max_request: 1000` каждый воркер проходит это раз в тысячу
запросов — без фазы drain каждый рецикл ронял бы запросы в полёте.

Свой рантайм:

```php
$server->on('workerStop', static fn() => $registry->closeAll(drainTimeout: 10.0));
```

`drainTimeout` держите ниже `terminationGracePeriodSeconds` оркестратора.

## 9. Прогрев соединений на старте воркера

Первый запрос после старта платит за `connect` (десятки миллисекунд с TLS). Чтобы не платил:

```yaml
swoole_doctrine_pool:
  connections:
    default: { size: 20, min_idle: 4 }
```

На `swoole.worker_start` бандл откроет 4 соединения; обслуживание поддерживает их число после простоя.
Standalone: `(new Driver(registry: $registry))->poolFor($params)->warmUp()` в `workerStart`.

## 10. Failover, перезапуск Postgres, pgbouncer

- Соединение, умершее **в простое**, ловит `validate_idle_after` (по умолчанию проверка `SELECT 1`
  для соединений, простоявших > 5 с) — запрос приложения проходит без ошибки на новом соединении.
- Соединение, умершее **под запросом**, даёт `Doctrine\DBAL\Exception\ConnectionLost`: соединение
  уничтожено, транзакция откачена сервером. Повтор — на стороне приложения, и только для идемпотентных операций:

```php
try {
    return $this->db->transactional($work);
} catch (ConnectionLost) {
    return $this->db->transactional($work);   // один повтор на свежем соединении
}
```

- После failover старые соединения к бывшему primary доживают до `max_lifetime` (по умолчанию 1 ч) или
  до ошибки. Для быстрой смены — меньший `max_lifetime` либо `closeAll()` по внешнему сигналу.
- **pgbouncer в transaction-режиме**: серверные prepared statements отключены (как в DBAL по умолчанию),
  `DISCARD ALL` совместим с ним. `application_name` в DSN помогает отличать воркеры в `pg_stat_activity`.

## 11. Метрики и health-check

```php
final class PoolHealthController
{
    public function __construct(private readonly PoolStatsProviderInterface $stats) {}

    #[Route('/health/db-pool')]
    public function __invoke(): JsonResponse
    {
        $pools = array_map(static fn(PoolStats $s): array => $s->toArray(), $this->stats->snapshot());
        $degraded = array_filter($pools, static fn(array $p): bool => $p['acquire_timeouts_total'] > 0);

        return new JsonResponse(['pools' => $pools], $degraded ? 503 : 200);
    }
}
```

Снимок — по пулам **текущего воркера**; агрегируйте по `worker_id`. Prometheus: см. [stats.md](stats.ru.md).
Счётчики, по которым стоит алертить: `acquire_timeouts_total`, `rollbacks_on_release_total`, `validation_failures_total`.

## 12. Долгие выборки и большие результаты

`iterateAssociative()` / `Result::fetchAssociative()` читают **буферизованный** результат: pdo_pgsql забирает
весь результат с сервера при `execute()`. Значит:

- итерация по результату не держит сокет занятым, но держит память под все строки;
- результат можно дочитывать из другой корутины (не пытайтесь — но это не ошибка);
- для миллионов строк используйте `LIMIT/OFFSET` или keyset-пагинацию, серверные курсоры пакетом
  не поддерживаются (они требуют транзакции на всё время чтения — это допустимо, но lease будет занят).

## 13. Состояние сессии: `SET`, advisory locks, LISTEN

По умолчанию (`reset_on_release: discard`) при возврате выполняется `DISCARD ALL`: всё, что вы поставили
на сессии, исчезает вместе с запросом. Что из этого следует:

- `SET search_path`/`SET timezone` внутри запроса — безопасно, действует до конца запроса.
- `pg_advisory_lock()` (сессионный) снимается при release. Нужен lock на несколько запросов — используйте
  `pg_advisory_xact_lock()` в транзакции или внешний lock (например, Symfony Lock).
- `LISTEN` не переживёт release; для подписок держите отдельное соединение вне пула.
- `rollback_only` всё это оставляет — и следующий запрос на этом же соединении унаследует `search_path`.

## 14. Тесты приложения с пулом

`phpunit` без `Coroutine\run()` — прямой режим: одно соединение, транзакции работают, пула нет.
Тесты под `Coroutine\run()` — настоящий пул. Подробнее — [testing.md](testing.ru.md).

## 15. Лимит соединений Postgres

`size × workers (× инстансов)` ≤ `max_connections` минус запас на консоль, миграции, мониторинг.
Пример: 8 воркеров × `size: 20` = 160 при `max_connections = 200`. Если не помещается — pgbouncer
между приложением и Postgres (см. сценарий 10) или меньший `size` с большим `acquire_timeout`.
Как вывести `size` из RPS и времени удержания и когда баунсер действительно нужен —
[configuration.md → Как подбирать](configuration.ru.md#как-подбирать).
