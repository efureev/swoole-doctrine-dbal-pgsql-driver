<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Packages\Doctrine\DBAL\PgSQL\ConnectionPoolConfig;
use Swoole\Packages\Doctrine\DBAL\PgSQL\ConnectionPoolKeeper;
use Swoole\Packages\Doctrine\DBAL\PgSQL\Scaler;

require_once 'vendor/autoload.php';

$connectionParams = include(__DIR__ . '/db-config.php');

$pool = (new \Swoole\Packages\Doctrine\DBAL\PgSQL\ConnectionPoolFactory())
    ->factory(new ConnectionPoolConfig($connectionParams));


$keeper = new ConnectionPoolKeeper();
$keeper->set($pool);
$driverMw = new \Swoole\Packages\Doctrine\DBAL\PgSQL\DriverKeeperMiddleware($keeper);
$driverMw->setFactory(new \Swoole\Packages\Doctrine\DBAL\PgSQL\ConnectionPoolFactory());

$configuration = new \Doctrine\DBAL\Configuration();
$configuration->setMiddlewares([$driverMw]);

$connFactory = static fn() => \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $configuration);

$scaler = new Scaler(
    $pool,
    $connectionParams['tickFrequency']
); // will try to free idle connect on connectionTtl overdue

$server = new Swoole\HTTP\Server("0.0.0.0", 9501);

$server->on("Start", function (Server $server) {
    echo "Swoole http server is started at http://0.0.0.0:9501\n";
});

$server->on("Request", function (Request $request, Response $response) use ($connFactory) {
    go(static function () use ($connFactory, $response) {
        $conn = $connFactory();
        $conn->fetchOne('SELECT version()');
        $conn->fetchOne('SELECT pg_sleep(2)');
        defer(static fn() => $conn->close());
        $response->header("Content-Type", "text/plain");
        $response->end('End');
    });
});

$server->on('workerstart', function () use ($scaler) {
    $scaler->run();
});

$server->on('workerstop', function () use ($pool, $scaler) {
    $pool->close();
    $scaler->close();
});

$server->start();
