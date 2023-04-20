<?php

declare(strict_types=1);

return [
    'dbname' => 'mydb',
    'user' => 'user',
    'password' => 'secret',
    'host' => 'dbhost',
    'driverClass' => \Swoole\Packages\Doctrine\DBAL\PgSQL\Driver::class,
    'server_version' => '15',
    'options' => [
        'poolSize' => 5, // MAX count connections in one pool
        'tickFrequency' => 60000, // when need check possibilities downscale (close) opened connection to DB in pools
        'connectionTTL' => 60, // when connection not used this time(seconds) - it will be close (free)
        'usedTimes' => 100, // 1 connection (in pool) will be re-used maximum N queries
        'connectionDelay' => 2, // time(seconds) for waiting response from pool
        'useConnectionPool' => true, // if false, will create new connect instead of using pool
        'retryMaxAttempts' => 2, // if connection in pool was timeout (before use) then try re-connect
        'retryDelay' => 1000, // if connection in pool was timeout (before use) then try re-connect
    ]
];
