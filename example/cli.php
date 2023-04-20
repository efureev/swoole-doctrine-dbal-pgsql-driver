#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

$connectionParams = include(__DIR__ . '/db-config.php');

$driverMw = new \Swoole\Packages\Doctrine\DBAL\PgSQL\DriverKeeperMiddleware();
$driverMw->setFactory(new \Swoole\Packages\Doctrine\DBAL\PgSQL\ConnectionPoolFactory());
$configuration = new \Doctrine\DBAL\Configuration();
$configuration->setMiddlewares([$driverMw]);

$connFactory = static fn() => \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $configuration);

Co\run(function () use ($connFactory) {
    for ($i = 1; $i <= 5; $i++) { // get 5 connection and make 5 async calls (2 SQL queries in each connection)
        go(static function () use ($connFactory, $i) {
            $conn = $connFactory();
            $v = $conn->fetchOne('SELECT version()');
            echo $v . PHP_EOL;
            $conn->fetchOne('SELECT pg_sleep(1)');
            echo 'routine #: ' . $i . PHP_EOL;
            defer(static fn() => $conn->close());
        });
    }
    // If repeat this again 50 times then each 5 connection will have same connection to DB by 100 queries count
    // 500 SQL for 50sec (instead of 250sec)
});
