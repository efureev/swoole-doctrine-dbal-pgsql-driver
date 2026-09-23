[English](troubleshooting.md) | **Русский**

# Диагностика

| Симптом | Причина | Что делать |
|---|---|---|
| warning `SWOOLE_HOOK_PDO_PGSQL не включён` в HTTP-воркере | хук pdo_pgsql не в `hook_flags` или Swoole собран без `--enable-swoole-pgsql` — PDO блокирует воркер на каждом запросе | `SWOOLE_HOOK_ALL` в `hook_flags`; `php --ri swoole` должен показывать `coroutine_pgsql => enabled`. В консоли и в master warning ожидаем |
| `PoolExhaustedException` | все `size` соединений заняты дольше `acquire_timeout` | поднять `size`, укоротить удержание (закрывать соединение раньше — `close()`), проверить `rollbacks_on_release_total` и долгие запросы |
| Запросы под нагрузкой идут последовательно, воркер «замирает» | PDO без хука блокирует воркер | см. первую строку; `HookSanityTest` проверяет именно это |
| `LeaseViolationException: … принадлежит корутине N` | `Statement` создан в одной корутине, выполнен в другой (например, из `go()` в обработчике) | брать соединение в той корутине, где выполняется запрос |
| `LeaseViolationException: … уже освобождён` | `Statement`/`Result` пережил запрос (сохранён в сервисе) | не кэшировать statement между запросами |
| `LeaseViolationException: … нет lease пула` | middleware изменил параметры соединения, драйвер и обёртка считают разные ключи пула | не переписывать `host/port/dbname/user/password` в middleware |
| warning `соединение возвращено с открытой транзакцией` | обработчик вышел между `beginTransaction()` и `commit()` без `rollBack()` (исключение) | `transactional()` или `try/finally`; данные откачены, ничего не потеряно |
| warning `пулы созданы в процессе … отброшены` | обращение к БД до fork (при загрузке ядра в master) | не ходить в БД при boot; прогрев — на `worker_start` |
| `InvalidConfigurationException: wrapperClass …` | `wrapper_class` не `CoroutineSafeConnection` или отсутствует (standalone) | указать `wrapperClass` |
| `InvalidConfigurationException: … только PostgreSQL-драйверы` | `url: mysql://` или `driver: pdo_mysql` (значение по умолчанию DoctrineBundle без `url`) | указать драйвер/схему Postgres |
| Воркер при reload ждёт `max_wait_time` | жив таймер обслуживания пула (`maintenance_interval > 0`) | бандл делает drain на `worker_exit`; со своим рантаймом зовите там `drainAll()` или оставьте `maintenance_interval` = 0 |
| Консольная команда или демон не завершается | таймер пула держит event loop | `maintenance_interval: 0` (по умолчанию) или `closeAll()` перед выходом; бандл закрывает пулы на `console.terminate` |
| Всплески 5xx на каждом рецикле воркера (`max_request`) | на `worker_exit` пулы закрыли вместо drain | бандл делает drain на `worker_exit`, close на `worker_stop`; проверьте теги слушателей, если переопределяли |
| `DISCARD ALL cannot run inside a transaction block` в логе | не должно случаться: откат идёт раньше; значит транзакция открылась между ними | сообщите с логом |
| `SET`/advisory lock/temp-таблица «переехали» в другой запрос | `reset_on_release: rollback_only` | вернуть `discard` или `RESET` вручную в конце запроса |
| ORM: сущности «чужого» запроса во flush | один `EntityManager` на несколько корутин | EntityManager на корутину |
| `Class "Pdo\Pgsql" not found` | штатный `Driver\PDO\PgSQL\Driver` DBAL используется напрямую со встроенным в Swoole pdo_pgsql | ходите через драйвер пула (`PoolDriverMiddleware` подменяет штатный) или `PgsqlDriver` |
| `Swoole\Error: API must be called in the coroutine` из PDO | хуки включены глобально, PDO вызван вне корутины | задавайте хуки через `Coroutine::set(['hook_flags' => …])`, PDO — внутри `Coroutine\run()` |

Полезные запросы:

```sql
SELECT pid, state, application_name, xact_start FROM pg_stat_activity WHERE datname = current_database();
```

`state = 'idle in transaction'` после ответа клиенту — соединение вернулось без отката: этого пул не допускает,
ищите обход пула (сырой PDO).
