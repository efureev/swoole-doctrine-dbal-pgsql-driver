<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

enum SlotResult
{
    case Acquired;
    case TimedOut;
    case Closed;
}
