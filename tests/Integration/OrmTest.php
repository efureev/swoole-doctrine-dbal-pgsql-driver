<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\CoversNothing;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Tests\Integration\Fixture\Item;
use SwooleDoctrinePool\Tests\Integration\Support\CoroutineTestCase;

use function array_unique;
use function count;

/**
 * ORM поверх пула: IDENTITY-генератор зовёт lastInsertId() отдельным вызовом после INSERT — он обязан
 * попасть в ту же сессию. UnitOfWork пакет coroutine-safe не делает: EntityManager — свой на корутину.
 */
#[CoversNothing]
final class OrmTest extends CoroutineTestCase
{
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$database?->exec(
            'CREATE TABLE IF NOT EXISTS public.sdp_orm_items (id serial PRIMARY KEY, marker varchar(255) NOT NULL)',
        );
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$database?->exec('DROP TABLE IF EXISTS public.sdp_orm_items');

        parent::tearDownAfterClass();
    }

    public function testConcurrentFlushesWithIdentityIdsGetUniqueIdsOnTheirOwnSessions(): void
    {
        $this->runInCoroutine(function (PoolRegistry $registry): void {
            $connection = self::connection($registry, ['size' => 4]);
            $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixture'], isDevMode: true);
            $config->enableNativeLazyObjects(true);
            $group = new WaitGroup();
            $ids = [];
            $errors = [];

            for ($i = 0; $i < 20; $i++) {
                $group->add();
                Coroutine::create(static function () use ($connection, $config, $group, $i, &$ids, &$errors): void {
                    try {
                        $em = new EntityManager($connection, $config);
                        $item = new Item();
                        $item->marker = 'orm-' . $i;
                        $em->wrapInTransaction(static function () use ($em, $item): void {
                            $em->persist($item);
                        });
                        $ids[] = $item->id;
                        $em->close();
                    } catch (\Throwable $e) {
                        $errors[] = $e->getMessage();
                    } finally {
                        $connection->close();
                    }

                    $group->done();
                });
            }

            $group->wait();

            self::assertSame([], $errors);
            self::assertCount(20, array_unique($ids), 'IDENTITY-id повторились: lastInsertId() ушёл на чужую сессию');
            self::assertSame(20, (int)$connection->fetchOne('SELECT count(*) FROM public.sdp_orm_items'));
        });
    }
}
