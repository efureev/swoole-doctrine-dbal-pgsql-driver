<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Pool;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SwooleDoctrinePool\Pool\PoolKey;

#[CoversClass(PoolKey::class)]
final class PoolKeyTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array PARAMS = [
        'host' => 'db',
        'port' => 5432,
        'dbname' => 'app',
        'user' => 'app',
        'password' => 'secret',
        'driverOptions' => ['pool' => ['size' => 3], PDO::ATTR_TIMEOUT => 5],
        'wrapperClass' => 'Whatever',
        'serverVersion' => '17',
    ];

    public function testEqualConnectionParamsProduceTheSameHash(): void
    {
        $other = self::PARAMS;
        $other['driverOptions']['pool']['size'] = 99;
        $other['wrapperClass'] = 'Other';
        $other['serverVersion'] = '16';

        self::assertSame(PoolKey::fromParams(self::PARAMS)->hash, PoolKey::fromParams($other)->hash);
    }

    public function testDifferentCredentialsOrDatabasesProduceDifferentHashes(): void
    {
        $base = PoolKey::fromParams(self::PARAMS)->hash;

        self::assertNotSame($base, PoolKey::fromParams(['password' => 'other'] + self::PARAMS)->hash);
        self::assertNotSame($base, PoolKey::fromParams(['dbname' => 'other'] + self::PARAMS)->hash);
        self::assertNotSame($base, PoolKey::fromParams(['host' => 'replica'] + self::PARAMS)->hash);
    }

    public function testPdoAttributesAreParTOfTheIdentity(): void
    {
        $other = self::PARAMS;
        $other['driverOptions'][PDO::ATTR_TIMEOUT] = 30;

        self::assertNotSame(PoolKey::fromParams(self::PARAMS)->hash, PoolKey::fromParams($other)->hash);
    }

    public function testTheLabelNamesTheTargetWithoutThePassword(): void
    {
        $key = PoolKey::fromParams(self::PARAMS);

        self::assertSame('app@db:5432/app', $key->label);
        self::assertStringNotContainsString('secret', $key->label);
    }
}
