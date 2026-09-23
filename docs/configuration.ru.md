[English](configuration.md) | **Русский**

# Конфигурация

Один источник значений по умолчанию — `SwooleDoctrinePool\Config\PoolConfig::DEFAULT_*`; Symfony-дерево
и standalone-режим читают одни и те же ключи.

## Symfony

```yaml
swoole_doctrine_pool:
  connections:
    default:                      # имя из doctrine.dbal.connections
      enabled: true
      size: 10
      min_idle: 0
      acquire_timeout: 5.0
      max_lifetime: 3600
      idle_timeout: 300
      max_uses: 0
      validate_idle_after: 5.0
      reset_on_release: discard
      maintenance_interval: 0
    analytics: ~                  # все значения по умолчанию
    legacy:
      enabled: false              # остаётся на обычном pdo_pgsql
```

## Без Symfony

Те же ключи — в `driverOptions['pool']` параметров DBAL:

```php
'driverOptions' => [
    'pool' => ['size' => 10, 'reset_on_release' => 'discard'],
    PDO::ATTR_TIMEOUT => 5,        // int-ключи уходят в PDO как есть
],
```

## Ключи

| Ключ | Тип | По умолчанию | Смысл |
|---|---|---|---|
| `enabled` | bool | `true` | `false` оставляет соединение на штатном драйвере DoctrineBundle |
| `size` | int ≥ 1 | 10 | Максимум одновременно открытых соединений пула **в одном воркере**. Это и предел конкурентных запросов к БД в воркере: lease держится до конца HTTP-запроса. `size × workers ≤ max_connections` |
| `min_idle` | int 0..size | 0 | Сколько простаивающих соединений держать открытыми. Открываются на `swoole.worker_start` (Symfony) или `PoolRegistry::warmUpAll()`; поддерживаются обслуживанием |
| `acquire_timeout` | float > 0, с | 5.0 | Сколько ждать свободное соединение, когда все `size` заняты. По истечении — `PoolExhaustedException` (наследует `Doctrine\DBAL\Exception\ConnectionException`), lease не выдаётся |
| `max_lifetime` | int ≥ 0, с | 3600 | Время жизни физического соединения; старше — закрывается при возврате или обслуживанием. Переживает failover, перезапуск pgbouncer, рост памяти бэкенда. `0` — без ограничения |
| `idle_timeout` | int ≥ 0, с | 300 | Простаивающее дольше — закрывается обслуживанием, но не ниже `min_idle`. `0` — никогда |
| `max_uses` | int ≥ 0 | 0 | Через сколько выдач закрыть соединение принудительно. Страховка от утечек сессии при `rollback_only`. `0` — без ограничения |
| `validate_idle_after` | float ≥ 0 \| `~`, с | 5.0 | Перед выдачей соединения, простоявшего дольше, выполняется `SELECT 1`; мёртвое молча заменяется. `0` — проверять всегда (round-trip на каждый acquire из idle), `~` — никогда |
| `reset_on_release` | `discard` \| `rollback_only` | `discard` | `discard` — `DISCARD ALL` при каждом возврате: сбрасывает `SET`, уровень изоляции сессии, temp-таблицы, advisory locks, LISTEN. `rollback_only` — только откат; быстрее на один round-trip, состояние сессии переживает запрос. **`ROLLBACK` незавершённой транзакции делается в обоих режимах** |
| `maintenance_interval` | int ≥ 0, с | 0 | `0` — без таймера: просроченные простаивающие закрываются лениво при возврате соединения (в полной тишине ничего не закрывается — до следующего release). Положительное значение добавляет `Swoole\Timer`, который чистит и в тишине и поддерживает `min_idle`. Живой таймер не даёт завершиться консольной команде и своему демону и заставляет `reload_async` ждать — включайте только в HTTP-воркерах |

## Значения из env

Symfony: числовые узлы принимают типизированные плейсхолдеры — `size: '%env(int:DB_POOL_SIZE)%'`,
`acquire_timeout: '%env(float:DB_POOL_ACQUIRE)%'`. `validate_idle_after` принимает любой плейсхолдер,
разбирает его `PoolConfig` в рантайме (`""`, `null`, `~` → «никогда»).

Standalone: `PoolConfig::fromArray()` сам приводит строки: `"10"` → 10, `"2.5"` → 2.5, `""`/`"null"`/`"~"` → null.
Неизвестный ключ — `InvalidConfigurationException` (защита от опечаток вроде `poolSize`).

## Как подбирать

Правильный пул — **маленький**. Пропускная способность Postgres упирается в ядра и диски, а не в соединения:
практический оптимум *активных* соединений на инстанс — около `2 × cores + диски` (≈ 16–20 на 8-ядерной
машине с SSD) — на весь кластер, а не на воркер. Выше — контекст-свитчи и lock contention, throughput падает.

### Формула

```
size                           = ceil(rps_на_воркер × avg_hold_seconds) + запас
size × worker_num × инстансов ≤ ~2 × cores Postgres         (иначе — pgBouncer, см. ниже)
size × worker_num × инстансов ≤ max_connections − запас на консоль, миграции, мониторинг
```

`avg_hold_seconds` — сколько запрос **держит** соединение: с первого запроса к БД до `kernel.terminate`
(или явного `close()`), а не только время SQL. Его отдаёт `LeaseReleased::heldSeconds`.

Пример: 10 воркеров, 300 RPS на инстанс, hold 20 мс → 6 занятых соединений на инстанс, то есть меньше одного
на воркер. `size: 10` держал бы 100 соединений на инстанс — в 15 раз больше нужного, а при пяти инстансах 500:
Postgres это не понравится.

### Стартовый профиль для хайлоада

```yaml
swoole_doctrine_pool:
  connections:
    default:
      size: 4                  # 4 × 10 воркеров = 40 на инстанс; поднимать только по acquire_timeouts_total
      min_idle: 2              # горячие после старта/рецикла воркера (max_request делает рецикл частым)
      acquire_timeout: 2.0     # меньше таймаута балансера: быстрый 503 лучше очереди
      max_lifetime: 1800
      idle_timeout: 60         # отдавать ёмкость после всплеска
      validate_idle_after: 5.0
      reset_on_release: discard
      maintenance_interval: 10 # только HTTP-воркеры: поддерживает min_idle и чистит в тишине
```

Со стороны Swoole: `worker_num` ≈ ядра инстанса (PHP CPU-bound), большой `max_request` (каждый рецикл
пересоздаёт пул).

### Что важнее, чем `size`

1. **Время удержания.** Соединение держится до `terminate`, включая внешние HTTP-вызовы, рендер и
   сериализацию после последнего запроса. Если обработчик долго работает после последнего SQL — вызовите
   `$connection->close()` сразу после него, и `size` можно уменьшить вдвое.
2. **`rollback_only`** убирает один round-trip на запрос (~0.1–0.3 мс + RTT) — только если код никогда не
   трогает сессию (`SET`, `SET SESSION CHARACTERISTICS`, `setTransactionIsolation()`, temp-таблицы, advisory
   locks). Страховка для этого режима: `max_uses: 1000`.
3. **`server_version`** в конфигурации DBAL: платформа строится без соединения.
4. **Не поднимайте `size` реактивно** на исчерпание. Сначала посмотрите, почему hold длинный (медленный запрос,
   N+1, ожидание внешнего сервиса с соединением на руках). Большой пул при медленных запросах лишь переносит
   очередь в Postgres, где она дороже.
5. **Отдельные пулы под разную нагрузку.** Долгим аналитическим запросам — своё DBAL-соединение и свой пул
   (`analytics: { size: 2, acquire_timeout: 30 }`), чтобы они не съедали слоты OLTP.

### По каким метрикам тюнить

| Сигнал | Смысл |
|---|---|
| `waiting > 0` устойчиво | `size` мал — или hold слишком длинный |
| `acquire_timeouts_total` растёт | то же; клиенты получают `PoolExhaustedException` (503) |
| `idle ≈ size` всегда | `size` избыточен; уменьшите и освободите память Postgres |
| `created_total` растёт быстро | рецикл воркеров или короткий `max_lifetime` |
| `rollbacks_on_release_total > 0` | обработчики оставляют транзакции открытыми — чините код |

### Когда маленького пула не хватает

- **Много инстансов/подов.** Пул ограничивает соединения на воркер, а не на кластер: 20 подов × 10 воркеров ×
  4 = 800 соединений. Ставьте **pgBouncer в transaction mode** (или PgCat/Odyssey) между приложением и
  Postgres; `size` можно оставить — реальный лимит держит баунсер. Пул уже совместим: серверные prepared
  statements выключены, `DISCARD ALL` — то, чего баунсер и ждёт. Ограничения transaction mode остаются
  (`LISTEN`, сессионные advisory locks, temp-таблицы между транзакциями).
- **Failover без перезапусков** — `PAUSE/RESUME` pgBouncer'а плавнее, чем ждать `ConnectionLost` +
  `max_lifetime`.

В остальных случаях pgBouncer не нужен: пул уже переиспользует соединения, сбрасывает сессии и ограничивает
их число.

### Остальные ключи

- `acquire_timeout` меньше таймаута балансировщика/клиента, иначе клиент уйдёт раньше, чем сервер ответит 503.
- `idle_timeout` ниже `max_lifetime`; `min_idle` — сколько соединений хочется иметь горячими после простоя.
