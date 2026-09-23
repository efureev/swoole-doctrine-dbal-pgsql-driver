<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Exception;

use Throwable;

/**
 * Маркер всех исключений пакета: `catch (SwooleDoctrinePool\Exception\Exception)` ловит любое из них.
 */
interface Exception extends Throwable
{
}
