<?php

/**
 * Стабы для символов, которых нет ни в одном пакете стабов.
 *
 * Загружается только psalm (см. psalm.xml) — в рантайме не автолоадится.
 */

declare(strict_types=1);

namespace Swoole\Coroutine {
    /**
     * Есть в ext-swoole; swoole/ide-helper документирует, но не объявляет.
     *
     * @param callable $fn
     * @param mixed ...$args
     */
    function run(callable $fn, mixed ...$args): bool
    {
    }
}
