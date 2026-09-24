<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

interface Clock
{
    /** Монотонные секунды: не зависят от перевода системных часов. */
    public function now(): float;
}
