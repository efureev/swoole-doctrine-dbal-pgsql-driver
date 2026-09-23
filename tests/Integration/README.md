# Интеграционные тесты

Настоящий Swoole (с `--enable-swoole-pgsql`) и настоящий Postgres 17. Без `ext-swoole` набор скипается;
без `POOL_TEST_DSN` — тоже, а не падает.

## Запуск

```bash
composer up              # Postgres 17 в docker (порт 5433 на хосте)
composer integration     # tests/Integration/run.sh: собирает образ на каждом BASE_IMAGE, гоняет набор
composer down
```

Любые аргументы уходят в phpunit: `tests/Integration/run.sh --filter Lease`.

Внутри контейнера с Swoole: `POOL_TEST_DSN=pgsql://pool:pool@postgres:5432/pool composer test-integration`.

## Матрица образов

| Образ | PHP | Swoole | Роль |
|---|---|---|---|
| `phpswoole/swoole:6.2.3-php8.5-zts` | 8.5 ZTS | 6.2.3 | по умолчанию — рабочая сборка под Swoole |
| `phpswoole/swoole:6.2.3-php8.5` | 8.5 NTS | 6.2.3 | `SWOOLE_IMAGES="phpswoole/swoole:6.2.3-php8.5" composer integration`; в CI — второй элемент матрицы |
| `gitreg.xbet.lan/web/main/ops/images/php8_5_zts_cli_ubuntu_24_04_swoole:1.1.0` | 8.5 ZTS | 6.2.2 | прод-образ (штатный `pdo_pgsql` + swoole-pgsql, `Pdo\Pgsql` есть); только из внутренней сети: `SWOOLE_IMAGES="gitreg.xbet.lan/…:1.1.0" composer integration` |

`docker/prepare.sh` проверяет: Swoole ≥ 6.2, `SWOOLE_HOOK_PDO_PGSQL` определён, PDO-драйвер `pgsql` есть,
хук включается. Матрица дублируется в `run.sh`, `.github/workflows/ci.yml` и этой таблице.

## Что и от чего защищает

| Тест | Гарантия | Сломай — покраснеет |
|---|---|---|
| `HookSanityTest` | pdo_pgsql под хуком уступает планировщику | убрать `SWOOLE_HOOK_PDO_PGSQL` из bootstrap |
| `LeaseTest::…ReusesOneBackend` | одна корутина — одна сессия | брать lease на каждый запрос |
| `LeaseTest::…NeverShareABackend` | PDO не делится между корутинами; ≤ `size` соединений | ключ контекста без cid |
| `LeaseTest::…OutsideTheParentTransaction` | дочерняя корутина — свой lease | наследовать lease родителя |
| `LeaseTest::…LastInsertId…` | `lastInsertId()` на сессии INSERT | statement-scoped lease |
| `LeaseTest::…RolledBackWhenTheCoroutineEnds` | незакоммиченное откатывается через defer; бэкенд `idle`, не `idle in transaction` | убрать `ROLLBACK` из `Pool::release()` |
| `LeaseTest::…RawBegin…` | серверное состояние транзакции (`PDO::inTransaction()`) | проверять уровень DBAL вместо PDO |
| `LeaseTest::…DoesNotLeak…Discard` / `…DoesLeak…RollbackOnly` | `DISCARD ALL` сбрасывает сессию; `rollback_only` — честно нет | убрать `DISCARD ALL` |
| `LeaseTest::…FromAnotherCoroutineIsRejected` | `LeaseViolationException`, транзакция владельца цела | убрать проверку cid в `LeaseAwareStatement` |
| `LeaseTest::…TwoDsns…` | пул на DSN | один пул в реестре |
| `ResilienceTest::…KilledBackend…` | `ConnectionLost`, соединение не переиспользуется, состояние транзакции сброшено | не помечать broken / не апгрейдить 08006 |
| `ResilienceTest::…ExhaustionTimesOut…` | `PoolExhaustedException` по `acquire_timeout`, реактор не заблокирован | `usleep` вместо `Channel::pop` |
| `ResilienceTest::…WaiterIsWoken…` | release будит ожидающего | не возвращать токен при release |
| `ResilienceTest::…KilledWhileIdle` | `validate_idle_after` ловит мёртвое idle-соединение | убрать `SELECT 1` |
| `ResilienceTest::…ClosedByMaintenance` | обслуживание закрывает сверх `min_idle` | сломать `maintain()` |
| `ResilienceTest::…MaxLifetime…` | пересоздание без ошибок и без превышения `size` | `expiredReason()` без `max_lifetime` |
| `ResilienceTest::…ClosingThePool…` | close ждёт занятых; запрос после close получает новый пул, а не закрытый | не ждать `inUse`; не перепроверять `isClosed()` в `VirtualConnection` |
| `SymfonyDoctrineTest` | контейнер Symfony + DoctrineBundle + ORM: общий сервис `Connection` под конкурентными корутинами — изолированные транзакции и savepoint'ы, rollback через `transactional()`/ORM, откат пулом брошенной транзакции на `kernel.terminate`, rollback-only per request, уникальные IDENTITY-id при 12 конкурентных flush, исчерпание → `PoolExhaustedException`, `ConnectionLost` внутри запроса, `console.terminate` закрывает пулы, `worker_exit` drain / `worker_stop` close | любой из соответствующих механизмов |
| `ResilienceTest::…DirectMode` | вне корутины — прямое PDO без пула | создавать пул при cid −1 |
| `OrmTest` | IDENTITY-id уникальны при 20 конкурентных flush (EM на корутину) | statement-scoped lease |
| `SymfonyLifecycleTest` | `url:` → middleware подставляет пул; `kernel.terminate` возвращает; `swoole.worker_stop` закрывает | убрать тег middleware / слушатели |

Новый регрессионный тест — новая строка в этой таблице.

## Что не покрыто

- fork из master с уже созданным пулом (pid-guard) — проверяется юнит-логикой `PoolRegistry::guardProcess()`;
- HTTP-сервер под нагрузкой (worker restart, `reload_async`) — запланировано.
