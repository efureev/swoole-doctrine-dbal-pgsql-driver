# doctrine-dbal-swoole-pgsql-driver

Doctrine DBAL Driver for Swoole 5 Postgresql database connections

## Installation

The easiest way to install this package is through composer:

```bash
$ composer require efureev/swoole-doctrine-dbal-pgsql-driver
```

## Example

You can test functionality using supplied docker image, located in [example](example) folder. Cli example can be found
in [example/cli.php](example/cli.php). HTTP server example can be found in [example/server.php](example/server.php)

App Config:

```yaml
doctrine:
  dbal:
    default_connection: 'swoole'
    connections:
      swoole:
        dbname: '%env(DB_MASTER_NAME)%'
        host: '%env(DB_MASTER_HOST)%'
        port: '%env(DB_MASTER_PORT)%'
        user: '%env(DB_MASTER_USER)%'
        password: '%env(DB_MASTER_PASS)%'
        driver_class: 'Swoole\Packages\Doctrine\DBAL\PgSQL\Driver'
        server_version: '15'
        options:
          poolSize: 3 # MAX count connections in one pool
          usedTimes: 3 # 1 connection (in pool) will be re-used maximum N queries
          connectionTTL: 60 # when connection not used this time(seconds) - it will be close (free)
          tickFrequency: 60000 # when need check possibilities downscale (close) opened connection to DB in pools
          connectionDelay: 2 # time(seconds) for waiting response from pool
          useConnectionPool: true # if false, will create new connect instead of using pool
          retryMaxAttempts: 2 # if connection in pool was timeout (before use) then try re-connect
          retryDelay: 1000 # delay to try fetch from pool again(milliseconds) if no connect available
```

Add Bundle to the `bundles.php`:

```php
[
    SwooleDoctrineDbalPoolBundle::class => ['all' => true],
]

```

It's all.
