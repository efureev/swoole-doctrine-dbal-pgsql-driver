**English** | [Русский](troubleshooting.ru.md)

# Troubleshooting

| Symptom | Cause | What to do |
|---|---|---|
| warning `SWOOLE_HOOK_PDO_PGSQL не включён` in an HTTP worker | the pdo_pgsql hook is not in `hook_flags`, or Swoole was built without `--enable-swoole-pgsql` — PDO blocks the worker on every query | `SWOOLE_HOOK_ALL` in `hook_flags`; `php --ri swoole` must show `coroutine_pgsql => enabled`. In a console command or the master the warning is expected |
| `PoolExhaustedException` | all `size` connections busy for longer than `acquire_timeout` | raise `size`, shorten holding (release earlier with `close()`), check `rollbacks_on_release_total` and slow queries |
| Requests under load run sequentially, the worker "freezes" | PDO without the hook blocks the worker | see the first row; `HookSanityTest` checks exactly this |
| `LeaseViolationException: … belongs to coroutine N` | a `Statement` created in one coroutine executed in another (e.g. from `go()` inside a handler) | take the connection in the coroutine that executes the query |
| `LeaseViolationException: … already released` | a `Statement`/`Result` outlived the request (stored in a service) | do not cache statements across requests |
| `LeaseViolationException: … no lease for pool` | a middleware changed the connection parameters, so the driver and the wrapper compute different pool keys | do not rewrite `host/port/dbname/user/password` in a middleware |
| warning `connection returned with an open transaction` | the handler left between `beginTransaction()` and `commit()` without `rollBack()` (an exception) | `transactional()` or `try/finally`; the data was rolled back, nothing lost |
| warning `pools created in process … discarded` | the database was touched before fork (during kernel boot in the master) | no DB access at boot; warm up on `worker_start` |
| `InvalidConfigurationException: wrapperClass …` | `wrapper_class` is not `CoroutineSafeConnection` or is missing (standalone) | set `wrapperClass` |
| `InvalidConfigurationException: … PostgreSQL drivers only` | `url: mysql://` or `driver: pdo_mysql` (DoctrineBundle's default without `url`) | specify a Postgres driver/scheme |
| The worker waits `max_wait_time` on reload | a pool maintenance timer is alive (`maintenance_interval > 0`) | the bundle drains on `worker_exit`; with your own runtime call `drainAll()` there, or leave `maintenance_interval` at 0 |
| A console command or daemon never exits | a pool timer keeps the event loop alive | `maintenance_interval: 0` (default), or `closeAll()` before returning; the bundle closes pools on `console.terminate` |
| 5xx bursts on every worker recycle (`max_request`) | pools closed on `worker_exit` instead of drained | the bundle drains on `worker_exit` and closes on `worker_stop`; check the listener tags if you rewired them |
| `DISCARD ALL cannot run inside a transaction block` in the log | should never happen: the rollback runs first; a transaction opened in between | report it with the log |
| `SET`/advisory lock/temp table "moved" into another request | `reset_on_release: rollback_only` | go back to `discard`, or `RESET` manually at the end of the request |
| ORM: entities of a "foreign" request in flush | one `EntityManager` shared by several coroutines | an EntityManager per coroutine |
| `Class "Pdo\Pgsql" not found` | DBAL's stock `Driver\PDO\PgSQL\Driver` is used directly with Swoole's pdo_pgsql | go through the pool driver (`PoolDriverMiddleware` replaces the stock driver) or `PgsqlDriver` |
| `Swoole\Error: API must be called in the coroutine` from PDO | hooks enabled globally, PDO used outside a coroutine | set hooks via `Coroutine::set(['hook_flags' => …])`, use PDO inside `Coroutine\run()` |

Useful queries:

```sql
SELECT pid, state, application_name, xact_start FROM pg_stat_activity WHERE datname = current_database();
```

`state = 'idle in transaction'` after the client got its response means a connection came back without a
rollback: the pool does not allow that, so look for code bypassing the pool (raw PDO).
