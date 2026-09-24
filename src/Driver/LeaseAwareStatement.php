<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Driver;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Override;
use PDOException;
use SwooleDoctrinePool\Coroutine\CoroutineApi;
use SwooleDoctrinePool\Lease\Lease;
use SwooleDoctrinePool\Pool\LostConnectionClassifier;

/**
 * Statement, привязанный к lease: выполнить его после release или из чужой корутины нельзя —
 * иначе запрос ушёл бы в транзакцию другого запроса на том же соединении.
 */
final class LeaseAwareStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly Lease $lease,
        private readonly CoroutineApi $api,
        private readonly LostConnectionClassifier $classifier,
    ) {
        parent::__construct($statement);
    }

    #[Override]
    public function execute(): Result
    {
        $this->lease->assertUsable($this->api->cid(), 'Statement::execute()');

        try {
            $result = parent::execute();
        } catch (DriverException | PDOException $e) {
            if ($this->classifier->isLost($e)) {
                $this->lease->markBroken();
            }

            throw $e;
        }

        return new LeaseAwareResult($result, $this->lease);
    }
}
