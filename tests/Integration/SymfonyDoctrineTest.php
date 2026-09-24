<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Doctrine\ORM\Configuration as OrmConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use SwooleDoctrinePool\DBAL\CoroutineSafeConnection;
use SwooleDoctrinePool\Exception\PoolExhaustedException;
use SwooleDoctrinePool\Pool\PoolRegistry;
use SwooleDoctrinePool\Pool\PoolStats;
use SwooleDoctrinePool\Tests\Integration\Fixture\Note;
use SwooleDoctrinePool\Tests\Integration\Support\CoroutineTestCase;
use SwooleDoctrinePool\Tests\Unit\Bridge\Symfony\Fixture\TestKernel;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\Event;
use Throwable;

use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function random_int;
use function sprintf;

use function Swoole\Coroutine\run;

/**
 * Настоящее ядро Symfony (FrameworkBundle + DoctrineBundle с ORM + бандл пакета) и общие сервисы
 * контейнера под конкурентными корутинами: так это выглядит в HTTP-воркере. Каждый сценарий —
 * «запрос»: работа в своей корутине, затем kernel.terminate из неё же. Между сценариями проверяются
 * инварианты пула: все соединения возвращены, открытых не больше size, откатов при release нет,
 * если сценарий их не предусматривает.
 */
#[CoversNothing]
final class SymfonyDoctrineTest extends CoroutineTestCase
{
    private const string TABLE = 'public.sdp_symfony_notes';

    private ?TestKernel $kernel = null;
    private PoolRegistry $registry;
    private ManagerRegistry $doctrine;
    private EventDispatcherInterface $dispatcher;
    private OrmConfiguration $ormConfig;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$database?->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id serial PRIMARY KEY, body varchar(255) NOT NULL)',
        );
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$database?->exec('DROP TABLE IF EXISTS ' . self::TABLE);

        parent::tearDownAfterClass();
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->kernel = new TestKernel(__DIR__ . '/Fixture/config-orm.yaml');
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        /** @var PoolRegistry $registry */
        $registry = $container->get('test.pool_registry');
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        /** @var OrmConfiguration $ormConfig */
        $ormConfig = $container->get('test.orm_config');

        $this->registry = $registry;
        $this->doctrine = $doctrine;
        $this->dispatcher = $dispatcher;
        $this->ormConfig = $ormConfig;

        self::$database?->exec('TRUNCATE ' . self::TABLE);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->kernel !== null) {
            $dir = $this->kernel->getProjectDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove($dir);
        }

        parent::tearDown();
    }

    public function testTheContainerConnectionIsPooledAndKernelTerminateReturnsItToTheSameBackend(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();
            self::assertInstanceOf(CoroutineSafeConnection::class, $connection);

            $first = $this->request(static fn(): int => self::backendPid($connection));
            $this->assertPoolQuiet(inUse: 0, idle: 1);

            $second = $this->request(static fn(): int => self::backendPid($connection));

            self::assertSame($first, $second, 'Последовательные запросы переиспользуют один бэкенд');
            self::assertSame(1, $this->stats()->createdTotal);
        });
    }

    public function testConcurrentRequestsRunIsolatedTransactionsOverOneSharedConnectionService(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();
            $group = new WaitGroup();
            $observed = [];
            $pids = [];

            for ($i = 0; $i < 4; $i++) {
                $group->add();
                Coroutine::create(function () use ($connection, $group, $i, &$observed, &$pids): void {
                    $this->request(function () use ($connection, $i, &$observed, &$pids): void {
                        $connection->beginTransaction();
                        $connection->executeStatement('INSERT INTO ' . self::TABLE . ' (body) VALUES (?)', ['tx-' . $i]);
                        $pids[] = self::backendPid($connection);
                        // Соседи в это же время внутри своих транзакций — уровень должен остаться 1.
                        Coroutine::sleep(0.05);
                        $observed[] = $connection->getTransactionNestingLevel();
                        $connection->beginTransaction();               // SAVEPOINT на своём соединении
                        $connection->executeStatement('INSERT INTO ' . self::TABLE . ' (body) VALUES (?)', ['nested-' . $i]);
                        $connection->rollBack();                        // откат только savepoint
                        $connection->commit();
                    });
                    $group->done();
                });
            }

            $group->wait();

            self::assertSame([1, 1, 1, 1], $observed, 'Уровень вложенности не протёк между корутинами');
            self::assertCount(4, array_unique($pids), 'Каждый запрос — на своём бэкенде');
            self::assertSame(4, $this->rows("body LIKE 'tx-%'"), 'Внешние транзакции закоммичены');
            self::assertSame(0, $this->rows("body LIKE 'nested-%'"), 'Вложенные откачены до savepoint');
            $this->assertPoolQuiet(inUse: 0, idle: 4);
        });
    }

    /**
     * Пять «запросов» с внешней и вложенной транзакцией, шаги перемешаны случайными паузами.
     * Доказательство на уровне Postgres: внутри запроса pg_backend_pid() и txid_current() постоянны на всех
     * шагах (savepoint не меняет top-level xid), между запросами — все различны; в середине ровно пять
     * бэкендов в транзакции; после — ни одного; закоммиченные строки несут xmin своего запроса.
     *
     * @psalm-suppress UnusedVariable переменные барьера читаются замыканиями по ссылке
     */
    public function testFiveInterleavedRequestsWithNestedTransactionsNeverShareASessionOrATransaction(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();
            $group = new WaitGroup();
            $arrived = 0;
            $release = false;
            $trace = [];        // [request, step, pid, txid, level]
            $errors = [];
            $xminInside = [];   // request => xmin строки outer-N, прочитанный внутри её же транзакции

            $step = static function (Connection $db, int $i, string $name, array &$trace): void {
                $row = $db->fetchNumeric('SELECT pg_backend_pid(), txid_current()');
                $trace[] = [$i, $name, (int)$row[0], (int)$row[1], $db->getTransactionNestingLevel()];
                Coroutine::sleep(random_int(1, 20) / 1000);
            };

            for ($i = 1; $i <= 5; $i++) {
                $group->add();
                Coroutine::create(function () use ($connection, $group, $i, $step, &$arrived, &$release, &$trace, &$errors, &$xminInside): void {
                    try {
                        $this->request(function () use ($connection, $i, $step, &$arrived, &$release, &$trace, &$xminInside): void {
                            $connection->beginTransaction();
                            $connection->executeStatement('INSERT INTO ' . self::TABLE . ' (body) VALUES (?)', ["outer-{$i}"]);
                            // Вставка на верхнем уровне: xmin кортежа — top-level xid именно этой транзакции.
                            $xminInside[$i] = (int)$connection->fetchOne(
                                'SELECT xmin::text::bigint FROM ' . self::TABLE . ' WHERE body = ?',
                                ["outer-{$i}"],
                            );
                            $step($connection, $i, 'outer', $trace);

                            $connection->beginTransaction();                                    // SAVEPOINT
                            $connection->executeStatement('INSERT INTO ' . self::TABLE . ' (body) VALUES (?)', ["nested-{$i}"]);
                            $step($connection, $i, 'nested', $trace);

                            // Барьер: все пять держат открытые транзакции одновременно.
                            $arrived++;
                            while (!$release) {
                                Coroutine::sleep(0.005);
                            }

                            if ($i % 2 === 0) {
                                $connection->commit();                                          // RELEASE SAVEPOINT
                            } else {
                                $connection->rollBack();                                        // ROLLBACK TO SAVEPOINT
                            }
                            $step($connection, $i, 'after-nested', $trace);

                            // После ROLLBACK TO SAVEPOINT Postgres открывает новую субтранзакцию, поэтому здесь
                            // только чтение: UPDATE дал бы кортежу xmin субтранзакции и сломал бы сверку ниже.
                            self::assertSame(1, (int)$connection->fetchOne(
                                'SELECT count(*) FROM ' . self::TABLE . ' WHERE body = ?',
                                ["outer-{$i}"],
                            ));
                            $step($connection, $i, 'before-commit', $trace);
                            $connection->commit();
                        });
                    } catch (Throwable $e) {
                        $errors[] = "request {$i}: " . $e->getMessage();
                        $release = true;
                    }

                    $group->done();
                });
            }

            // Ждём, пока все пять окажутся внутри вложенной транзакции, и смотрим на сервер со стороны.
            for ($tick = 0; $tick < 400 && $arrived < 5; $tick++) {
                Coroutine::sleep(0.005);
            }
            self::assertSame(5, $arrived, 'Не все запросы дошли до барьера: ' . implode('; ', $errors));
            $midCount = self::db()->backendsInTransaction('swoole-doctrine-pool-symfony');
            $release = true;
            $group->wait();

            self::assertSame([], $errors);
            self::assertSame(5, $midCount, 'В середине ровно пять бэкендов держат транзакцию — по одному на запрос');

            // Внутри запроса: один pid, один top-level txid, уровни 1 → 2 → 1 → 1.
            $byRequest = [];
            foreach ($trace as [$i, $name, $pid, $txid, $level]) {
                $byRequest[$i]['pids'][$pid] = true;
                $byRequest[$i]['txids'][$txid] = true;
                $byRequest[$i]['levels'][$name] = $level;
            }
            self::assertCount(5, $byRequest);
            foreach ($byRequest as $i => $r) {
                self::assertCount(1, $r['pids'], "Запрос {$i} сменил бэкенд посреди транзакции");
                self::assertCount(1, $r['txids'], "Запрос {$i} сменил транзакцию посреди работы");
                self::assertSame(
                    ['outer' => 1, 'nested' => 2, 'after-nested' => 1, 'before-commit' => 1],
                    $r['levels'],
                    "Уровень вложенности запроса {$i} искажён соседями",
                );
            }

            // Между запросами: пять разных бэкендов и пять разных транзакций.
            $pids = array_merge(...array_map(static fn(array $r): array => array_keys($r['pids']), array_values($byRequest)));
            $txids = array_merge(...array_map(static fn(array $r): array => array_keys($r['txids']), array_values($byRequest)));
            self::assertCount(5, array_unique($pids), 'Два запроса делили один бэкенд');
            self::assertCount(5, array_unique($txids), 'Два запроса делили одну транзакцию');

            // Шаги действительно чередовались, а не шли запрос за запросом.
            $switches = 0;
            for ($k = 1, $n = count($trace); $k < $n; $k++) {
                if ($trace[$k][0] !== $trace[$k - 1][0]) {
                    $switches++;
                }
            }
            self::assertGreaterThan(8, $switches, 'Трасса почти последовательная — конкурентности не было');

            // Результат в таблице: внешние строки всех пяти, вложенные — только чётных; xmin строки = txid её
            // запроса и внутри транзакции, и после commit — то есть её вставил именно этот запрос.
            self::assertSame(5, $this->rows("body LIKE 'outer-%'"));
            self::assertSame(2, $this->rows("body LIKE 'nested-%'"));
            foreach ($byRequest as $i => $r) {
                $txid = (int)array_key_first($r['txids']) % 4294967296;
                self::assertSame($txid, $xminInside[$i], "Вставка запроса {$i} ушла не в его транзакцию");
                self::assertSame(
                    1,
                    $this->rows(sprintf("body = 'outer-%d' AND xmin::text::bigint = %d", $i, $txid)),
                    "Строка запроса {$i} закоммичена чужой транзакцией",
                );
            }
            self::assertSame(0, self::db()->backendsInTransaction('swoole-doctrine-pool-symfony'), 'После запросов открытых транзакций нет');
            $this->assertPoolQuiet(inUse: 0, idle: 5);
            self::assertSame(0, $this->stats()->rollbacksOnReleaseTotal, 'Пулу не пришлось ничего откатывать');
        });
    }

    public function testAnExceptionInsideTransactionalRollsBackAndTheConnectionComesBackClean(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();
            $escaped = false;

            $this->request(function () use ($connection, &$escaped): void {
                try {
                    $connection->transactional(static function (Connection $db): void {
                        $db->executeStatement('INSERT INTO ' . self::TABLE . ' (body) VALUES (?)', ['doomed']);
                        throw new RuntimeException('business rule');
                    });
                } catch (RuntimeException) {
                    $escaped = true;
                }

                self::assertFalse($connection->isTransactionActive());
            });

            self::assertTrue($escaped);
            self::assertSame(0, $this->rows("body = 'doomed'"));
            $this->assertPoolQuiet(inUse: 0, idle: 1);
            self::assertSame(0, $this->stats()->rollbacksOnReleaseTotal, 'Откат сделал DBAL, а не пул');
        });
    }

    public function testARequestThatDiesMidTransactionIsRolledBackByThePoolOnTerminate(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();

            $this->request(function () use ($connection): void {
                $connection->beginTransaction();
                $connection->executeStatement('INSERT INTO ' . self::TABLE . ' (body) VALUES (?)', ['abandoned']);
                // Обработчик «упал» без rollBack: kernel.terminate всё равно вызывается (рантайм, finally).
            });

            self::assertSame(0, $this->rows("body = 'abandoned'"));
            self::assertSame(1, $this->stats()->rollbacksOnReleaseTotal);
            self::assertFalse($connection->isTransactionActive(), 'Состояние транзакции ушло вместе с lease');
            $this->assertPoolQuiet(inUse: 0, idle: 1);
        });
    }

    public function testRollbackOnlyIsPerRequest(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();

            $this->request(function () use ($connection): void {
                $connection->beginTransaction();
                $connection->setRollbackOnly();
                self::assertTrue($connection->isRollbackOnly());
                $connection->rollBack();
            });

            $this->request(function () use ($connection): void {
                try {
                    $connection->isRollbackOnly();
                    self::fail('Без транзакции isRollbackOnly() обязан бросать');
                } catch (NoActiveTransaction) {
                }

                $connection->beginTransaction();
                self::assertFalse($connection->isRollbackOnly(), 'Флаг прошлого запроса не протёк');
                $connection->commit();
            });
        });
    }

    public function testConcurrentEntityManagersOverTheContainerConnectionGetUniqueIdentityIds(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();
            $group = new WaitGroup();
            $ids = [];
            $errors = [];

            for ($i = 0; $i < 12; $i++) {
                $group->add();
                Coroutine::create(function () use ($connection, $group, $i, &$ids, &$errors): void {
                    try {
                        $this->request(function () use ($connection, $i, &$ids): void {
                            $em = new EntityManager($connection, $this->ormConfig);
                            $note = new Note();
                            $note->body = 'note-' . $i;
                            $em->wrapInTransaction(static function () use ($em, $note): void {
                                $em->persist($note);
                            });
                            $ids[] = $note->id;
                            $em->close();
                        });
                    } catch (Throwable $e) {
                        $errors[] = $e->getMessage();
                    }

                    $group->done();
                });
            }

            $group->wait();

            self::assertSame([], $errors);
            self::assertCount(12, array_unique($ids), 'IDENTITY-id повторились: lastInsertId() ушёл на чужую сессию');
            self::assertSame(12, $this->rows("body LIKE 'note-%'"));
            $this->assertPoolQuiet(inUse: 0, idle: null);
        });
    }

    public function testEntityManagerRollbackOnFailedFlushLeavesNoRowAndNoOpenTransaction(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();

            $this->request(function () use ($connection): void {
                $em = new EntityManager($connection, $this->ormConfig);
                $note = new Note();
                $note->body = 'rolled';

                try {
                    $em->wrapInTransaction(static function () use ($em, $note): void {
                        $em->persist($note);
                        $em->flush();
                        throw new RuntimeException('after flush');
                    });
                } catch (RuntimeException) {
                }

                self::assertFalse($em->getConnection()->isTransactionActive());
                self::assertFalse($em->isOpen(), 'ORM закрывает EntityManager после отката');
            });

            self::assertSame(0, $this->rows("body = 'rolled'"));
            self::assertSame(0, $this->stats()->rollbacksOnReleaseTotal);
        });
    }

    public function testPoolExhaustionSurfacesAsAConnectionExceptionThroughTheContainerConnection(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();
            $group = new WaitGroup();
            $exhausted = 0;

            // size: 5, acquire_timeout: 0.5 — шестая и седьмая корутины не дожидаются.
            for ($i = 0; $i < 7; $i++) {
                $group->add();
                Coroutine::create(function () use ($connection, $group, &$exhausted): void {
                    try {
                        $this->request(static function () use ($connection): void {
                            $connection->executeQuery('SELECT pg_sleep(0.8)');
                        });
                    } catch (PoolExhaustedException) {
                        $exhausted++;
                    }

                    $group->done();
                });
            }

            $group->wait();

            self::assertSame(2, $exhausted);
            self::assertSame(2, $this->stats()->acquireTimeoutsTotal);
            self::assertLessThanOrEqual(5, $this->stats()->createdTotal, 'Больше size соединений не открылось');
            $this->assertPoolQuiet(inUse: 0, idle: 5);
        });
    }

    public function testAKilledBackendInsideARequestIsReportedAsConnectionLostAndReplaced(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();

            $this->request(function () use ($connection): void {
                $connection->beginTransaction();
                $pid = self::backendPid($connection);
                self::db()->terminateBackend($pid);

                try {
                    $connection->fetchOne('SELECT 1');
                    self::fail('Ожидался ConnectionLost');
                } catch (ConnectionLost) {
                }

                self::assertFalse($connection->isTransactionActive());
                self::assertNotSame($pid, self::backendPid($connection), 'Тот же запрос продолжает на новом бэкенде');
            });

            self::assertSame(1, $this->stats()->closedTotal);
            $this->assertPoolQuiet(inUse: 0, idle: 1);
        });
    }

    public function testConsoleTerminateClosesThePoolsSoTheCommandProcessCanExit(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();
            self::assertSame(1, (int)$connection->fetchOne('SELECT 1'));
            self::assertSame(1, $this->registry->count());

            $this->dispatcher->dispatch(
                new ConsoleTerminateEvent(new Command('test:noop'), new ArrayInput([]), new NullOutput(), 0),
                'console.terminate',
            );

            self::assertSame(0, $this->registry->count(), 'После console.terminate пулов нет');
            self::assertSame(1, (int)$connection->fetchOne('SELECT 1'), 'Следующее обращение создаёт пул заново');
        });
    }

    public function testWorkerExitDrainsAndWorkerStopClosesWhileRequestsInFlightSurvive(): void
    {
        $this->inScheduler(function (): void {
            $connection = $this->dbal();
            $group = new WaitGroup();
            $group->add();
            $survived = false;

            Coroutine::create(function () use ($connection, $group, &$survived): void {
                $this->request(function () use ($connection, &$survived): void {
                    Coroutine::sleep(0.1);                              // ещё не трогал БД, когда пришёл worker_exit
                    $survived = (int)$connection->fetchOne('SELECT 1') === 1;
                });
                $group->done();
            });

            Coroutine::sleep(0.02);
            $this->dispatcher->dispatch(new Event(), 'swoole.worker_exit');
            $group->wait();

            self::assertTrue($survived, 'Запрос в полёте после worker_exit всё ещё получает соединение');
            self::assertSame(1, $this->registry->count(), 'worker_exit — drain, не close');

            $this->dispatcher->dispatch(new Event(), 'swoole.worker_stop');
            self::assertSame(0, $this->registry->count());
        });
    }

    /** Тело «запроса»: работа в текущей корутине, затем kernel.terminate — как делает рантайм. */
    private function request(callable $body): mixed
    {
        try {
            return $body();
        } finally {
            $this->dispatcher->dispatch(
                new TerminateEvent($this->kernel ?? throw new RuntimeException('no kernel'), Request::create('/'), new Response()),
                KernelEvents::TERMINATE,
            );
        }
    }

    private function inScheduler(callable $body): void
    {
        $error = null;

        run(function () use ($body, &$error): void {
            try {
                $body();
            } catch (Throwable $e) {
                $error = $e;
            } finally {
                $this->registry->closeAll(2.0);
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }

    private function dbal(): Connection
    {
        return $this->doctrine->getConnection();
    }

    private function stats(): PoolStats
    {
        $stats = $this->registry->stats();

        return $stats[array_key_first($stats) ?? self::fail('Пул ещё не создан')];
    }

    /** Проверки из главной корутины сами берут lease — отдаём его, чтобы смотреть только на «запросы». */
    private function assertPoolQuiet(int $inUse, ?int $idle): void
    {
        $this->registry->releaseCurrentCoroutine();
        $stats = $this->stats();
        self::assertSame($inUse, $stats->inUse, 'Соединения на руках после terminate');

        if ($idle !== null) {
            self::assertSame($idle, $stats->idle);
        }

        self::assertLessThanOrEqual($stats->size, $stats->open());
    }

    private function rows(string $where): int
    {
        try {
            return (int)$this->dbal()->fetchOne('SELECT count(*) FROM ' . self::TABLE . ' WHERE ' . $where);
        } finally {
            $this->registry->releaseCurrentCoroutine();
        }
    }
}
