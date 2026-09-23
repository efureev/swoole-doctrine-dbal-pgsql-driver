<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL);

if (extension_loaded('swoole')) {
    // Хуки действуют только внутри Coroutine\run(): под глобальным Runtime::enableCoroutine() PDO вне
    // корутины бросает «API must be called in the coroutine», а тестам нужно обычное PDO для схемы.
    \Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
}
