<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

interface ConnectionFactory
{
    /**
     * Открывает физическое соединение. Внутри корутины с включённым хуком pdo_pgsql — уступает управление.
     *
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function open(int $id): PhysicalConnection;
}
