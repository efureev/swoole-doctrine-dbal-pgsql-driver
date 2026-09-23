**English** | [Русский](index.ru.md)

# Documentation

| Document | Contents | When to open |
|---|---|---|
| **Start** | | |
| [getting-started.md](getting-started.md) | requirements, installation, wiring with and without Symfony, first load check | wiring the package in |
| [use-cases.md](use-cases.md) | 15 scenarios: HTTP handler, transactions, ORM, parallel queries, daemons, console, multiple databases, shutdown, warm-up, failover, metrics, large results, session state, tests, limits | want to know how the pool behaves in your code |
| **Configuration** | | |
| [configuration.md](configuration.md) | every `swoole_doctrine_pool.*` key: type, default, meaning; env values; **sizing for high load** (formula, starting profile, metrics to tune by, when pgBouncer) | tuning the pool for load |
| [symfony.md](symfony.md) | what the compiler pass injects, the middleware, listeners, `url:`/`DATABASE_URL`, monolog, configuration errors | wiring the bundle, or the container misbehaves |
| [standalone.md](standalone.md) | `DriverManager` + `PoolDriverMiddleware`, worker lifecycle, direct mode | using it without Symfony |
| **Mechanics** | | |
| [architecture.md](architecture.md) | layers and components, lease, acquire/release, accounting, execution flows, data-safety guarantees table | want to know exactly what happens to a connection |
| [events.md](events.md) | PSR-14 events, their fields and when they fire | writing metrics or audit |
| [stats.md](stats.md) | `PoolStats`, the provider, a Prometheus example, alerts | exporting pool state |
| **Operations** | | |
| [testing.md](testing.md) | testing an application with the pool (direct mode, coroutines), the package's own tests | writing tests |
| [troubleshooting.md](troubleshooting.md) | symptom → cause → what to do | something went wrong |
| [../UPGRADE-3.0.md](../UPGRADE-3.0.md) | step-by-step migration from v2 (Russian) | upgrading |
| [../CHANGELOG.md](../CHANGELOG.md) | change history (Russian) | |
