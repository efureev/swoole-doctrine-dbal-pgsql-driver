[English](testing.md) | **Русский**

# Тестирование

## Тесты приложения, использующего пул

### Юнит- и функциональные тесты без Swoole-планировщика

`phpunit` запускает тесты вне корутины (`Coroutine::getCid() === -1`) — драйвер работает в **прямом режиме**:
одно PDO-соединение, транзакции, `close()` — всё как с обычным `pdo_pgsql`, пула и таймеров нет.
Конфигурацию менять не нужно; `dama/doctrine-test-bundle` и подобные обёртки транзакций работают.

Если в тестовом окружении ext-swoole нет вовсе, пакет всё равно загружается: `Swoole\*` нужны только
при создании пула.

### Тесты внутри корутин

Чтобы проверить код, который спавнит корутины или зависит от пула:

```php
public function testParallelLookups(): void
{
    $error = null;

    \Swoole\Coroutine\run(function () use (&$error): void {
        try {
            $service = self::getContainer()->get(ProfileService::class);
            self::assertCount(2, $service->loadBoth(42));
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            self::getContainer()->get(PoolRegistry::class)->closeAll();   // таймер пула держит event loop
        }
    });

    if ($error !== null) {
        throw $error;
    }
}
```

Два правила:

- **`closeAll()` в `finally`** — таймер обслуживания пула не даёт `run()` завершиться.
- **хуки — через `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])` в bootstrap**, а не
  `Runtime::enableCoroutine()`: после глобального включения PDO вне корутины (в `setUp`, фикстурах)
  бросает `Swoole\Error: API must be called in the coroutine`.

Исключения из дочерних корутин наружу не выходят — собирайте их в переменную, как выше.

### Фикстуры и схема

Готовьте схему обычным `PDO`/DBAL в `setUpBeforeClass` вне корутины (прямой режим), а конкурентные сценарии —
внутри `run()`. Каждый тест-класс — своя схема (`CREATE SCHEMA t_<class>`), так тесты не мешают друг другу
и параллелятся.

## Тесты самого пакета

| Набор | Команда | Что нужно |
|---|---|---|
| `unit` | `composer test-unit` | ничего: без ext-swoole и без Postgres, на фейках |
| `integration` | `composer up && composer integration` | docker: Swoole 6.2.3 + Postgres 17 |
| всё для CI | `composer ci` | phpcs + psalm + unit |

`tests/Integration/run.sh` собирает образ на каждом `BASE_IMAGE` из матрицы (по умолчанию
`phpswoole/swoole:6.2.3-php8.5-zts`; `SWOOLE_IMAGES="…"` — своя), монтирует репозиторий в `/app`
и гоняет набор поверх Postgres из `compose.yaml`. Внутри контейнера тесты находят БД по `POOL_TEST_DSN`;
без него набор скипается.

Что проверяют интеграционные тесты и какое изменение сделает каждый из них красным —
[tests/Integration/README.md](../tests/Integration/README.md).

### Фейки для юнит-тестов

`tests/Unit/Fake/`: `FakeCoroutineApi` (переключение cid, defer по cid, таймеры), `FakePdo` (без соединения,
лог вызовов, ошибки по имени метода), `FakeInnerDriver`, `FakeConnectionFactory`, `RecordingDispatcher`,
`RecordingLogger`. `CountingSlots` — семафор без ожидания вместо `ChannelSlots`. Через них проверяются
инварианты учёта, порядок `ROLLBACK → DISCARD ALL`, изоляция транзакций по cid и поведение при потере
соединения — без Swoole и без БД.
