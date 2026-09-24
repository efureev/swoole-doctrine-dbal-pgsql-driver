[English](symfony.md) | **Русский**

# Symfony-бандл

`SwooleDoctrinePool\Bridge\Symfony\SwooleDoctrinePoolBundle` работает поверх DoctrineBundle ^3 и Symfony ^8.1.

## Что делает бандл

1. **Extension** читает `swoole_doctrine_pool.connections`, регистрирует `PoolRegistry` (один на воркер),
   `PoolDriverMiddleware` с тегом `doctrine.middleware` (`connection: <имя>`, `priority: 1024`) на каждое
   включённое соединение, слушатели и `PoolStatsProviderInterface`.
2. **`PoolConnectionPass`** находит `doctrine.dbal.<имя>_connection` и подставляет в параметры соединения
   `driverClass` = `SwooleDoctrinePool\Driver\Driver`, `wrapperClass` = `CoroutineSafeConnection`
   (или ваш наследник), `driverOptions['pool']` = опции из конфигурации. В `doctrine.yaml` ничего писать не нужно.
3. **`PoolDriverMiddleware`** — самый внутренний middleware (priority 1024 > 10 у DoctrineBundle): получает
   реальный `pdo_pgsql`-драйвер и оборачивает его в пул. Именно он гарантирует пул при `url:`.

### `url:` / `DATABASE_URL`

`ConnectionFactory` DoctrineBundle при `url:` с явной схемой **удаляет `driverClass`** из параметров в рантайме.
Поэтому пул подставляется middleware, а не только `driverClass`: с `url: postgresql://…` всё работает,
с `url: mysql://…` бандл упадёт с `InvalidConfigurationException` — пул только для Postgres.

### Ошибки конфигурации (на этапе сборки контейнера)

| Ошибка | Что сделать |
|---|---|
| соединение не найдено в `doctrine.dbal.connections` | опечатка в имени; в сообщении список известных |
| `driver_class` уже задан | уберите — его подставляет бандл |
| `wrapper_class` не наследует `CoroutineSafeConnection` | наследуйте её или уберите `wrapper_class` |
| опции пула в `doctrine...options.pool` | перенесите в `swoole_doctrine_pool` |
| `replica`/`primary` | replicas в 3.0 не поддерживаются |

## Рекомендации к `doctrine.yaml`

```yaml
doctrine:
  dbal:
    server_version: '17'        # без него DoctrineBundle подключится к БД при сборке платформы
    idle_connection_ttl: 0      # IdleConnectionMiddleware закрывает соединения на kernel.request — пулу это не нужно
```

## Слушатели

| Событие | Что делает | Приоритет |
|---|---|---|
| `kernel.terminate` | `PoolRegistry::releaseCurrentCoroutine()` — отдаёт соединение в пул сразу после ответа. В рантайме «корутина на запрос» выполняется в корутине запроса | −1024, после всех |
| `console.terminate` | release + `closeAll()`: процесс завершается, а живой таймер обслуживания не дал бы `Coroutine\run()` вернуться | −1024 |
| `swoole.worker_start` | прогрев: открывает `min_idle` соединений для пулов, где `min_idle > 0` | |
| `swoole.worker_exit` | `PoolRegistry::drainAll()` — воркер уходит (`reload_async`, `max_request`, перезапуск по памяти), но ещё дообслуживает запросы: таймеры сняты, idle закрыты, новые lease выдаются. Приходит повторно; идемпотентно | |
| `swoole.worker_stop`, `swoole.before_shutdown` | `PoolRegistry::closeAll()` — полное закрытие; идемпотентно | |

События `swoole.*` — строковые имена, которые должен диспатчить ваш Swoole-рантайм; зависимости на рантайм нет, без них слушатели
молчат. Свой рантайм → вызывайте `$registry->closeAll()` в `workerStop` сами (`PoolRegistry` доступен как сервис).

Safety-net без слушателей: lease освобождается в `Coroutine::defer` при завершении корутины.

## Логи

Канал monolog `swoole_pool`:

```yaml
monolog:
  channels: ['swoole_pool']
```

`warning` — возврат соединения с открытой транзакцией (выполнен `ROLLBACK`; это баг обработчика),
пулы из родительского процесса отброшены; `error` — неудачный `ROLLBACK`/`DISCARD ALL` (соединение уничтожено),
исключение слушателя событий.

## Проверка

```bash
bin/console debug:config swoole_doctrine_pool          # итоговые значения
bin/console debug:container database_connection        # класс CoroutineSafeConnection
```

## Границы

- Пакет делает coroutine-safe **DBAL**. `EntityManager`/UnitOfWork — один на корутину, это ответственность
  приложения (см. [use-cases.md, сценарий 3](use-cases.ru.md#3-orm-entitymanager-на-корутину)).
- `PrimaryReadReplicaConnection` (`replica:`) не поддерживается.
