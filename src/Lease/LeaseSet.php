<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Lease;

/**
 * Все lease одной корутины, по одному на пул. Хранится в контексте корутины под LeaseBinder::CONTEXT_KEY.
 */
final class LeaseSet
{
    /** @var array<string, Lease> hash пула => lease */
    public array $leases = [];
}
