<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin\Stats;

use App\Service\Admin\Stats\StatsFormat;
use PHPUnit\Framework\TestCase;

final class StatsFormatTest extends TestCase
{
    public function testCoinsComeAsMinorUnitsAndWholeCoins(): void
    {
        $this->assertSame(['minor' => '50000000000', 'coins' => 500], StatsFormat::coins(500));
        $this->assertSame(['minor' => '0', 'coins' => 0], StatsFormat::coins(0));
    }

    public function testMinorUnitsKeepTheFractionAndTruncateTheCoins(): void
    {
        $this->assertSame(['minor' => '250000000', 'coins' => 2], StatsFormat::minor('250000000'));
        $this->assertSame(['minor' => '5', 'coins' => 0], StatsFormat::minor(5));
        $this->assertSame(['minor' => '-40500000000', 'coins' => -405], StatsFormat::minor(-40500000000));
    }

    public function testTimestampsAreIsoUtc(): void
    {
        $this->assertSame('2026-10-07T10:00:00Z', StatsFormat::iso(['at' => '2026-10-07 10:00:00'], 'at'));
        $this->assertNull(StatsFormat::iso(['at' => null], 'at'));
        $this->assertNull(StatsFormat::iso([], 'at'));
        $this->assertSame('2026-10-07T10:00:00Z', StatsFormat::isoNow(new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('Europe/Paris'))));
    }

    public function testShareIsAPercentageAndZeroOnAnEmptyTotal(): void
    {
        $this->assertSame(33.33, StatsFormat::share(1, 3));
        $this->assertSame(0.0, StatsFormat::share(5, 0));
    }

    public function testEmptyJsonObjectStaysAnObject(): void
    {
        $this->assertSame('{}', json_encode(StatsFormat::jsonObject(['v' => '{}'], 'v')));
        $this->assertSame('{"a":1}', json_encode(StatsFormat::jsonObject(['v' => '{"a":1}'], 'v')));
    }

    public function testBooleansFromPostgresAndPhp(): void
    {
        $this->assertTrue(StatsFormat::bool(['v' => true], 'v'));
        $this->assertTrue(StatsFormat::bool(['v' => 't'], 'v'));
        $this->assertFalse(StatsFormat::bool(['v' => false], 'v'));
        $this->assertFalse(StatsFormat::bool([], 'v'));
    }
}
