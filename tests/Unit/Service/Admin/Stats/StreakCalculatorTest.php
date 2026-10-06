<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin\Stats;

use App\Service\Admin\Stats\StreakCalculator;
use PHPUnit\Framework\TestCase;

final class StreakCalculatorTest extends TestCase
{
    public function testNoDayMeansNoStreak(): void
    {
        $this->assertSame(['current' => 0, 'best' => 0, 'startedOn' => null, 'openedToday' => false], StreakCalculator::analyse([], '2026-10-07'));
    }

    public function testARunEndingTodayIsRunning(): void
    {
        $streak = StreakCalculator::analyse(['2026-10-07', '2026-10-05', '2026-10-06'], '2026-10-07');

        $this->assertSame(['current' => 3, 'best' => 3, 'startedOn' => '2026-10-05', 'openedToday' => true], $streak);
    }

    public function testARunEndingYesterdayIsAtRiskNotDead(): void
    {
        $streak = StreakCalculator::analyse(['2026-10-05', '2026-10-06'], '2026-10-07');

        $this->assertSame(['current' => 2, 'best' => 2, 'startedOn' => '2026-10-05', 'openedToday' => false], $streak);
    }

    public function testARunOlderThanYesterdayIsDeadButKeepsItsBest(): void
    {
        $streak = StreakCalculator::analyse(['2026-10-01', '2026-10-02', '2026-10-03'], '2026-10-07');

        $this->assertSame(['current' => 0, 'best' => 3, 'startedOn' => null, 'openedToday' => false], $streak);
    }

    public function testAGapStartsANewSeriesAndBestKeepsTheLongest(): void
    {
        $streak = StreakCalculator::analyse(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-10-06', '2026-10-07'], '2026-10-07');

        $this->assertSame(['current' => 2, 'best' => 4, 'startedOn' => '2026-10-06', 'openedToday' => true], $streak);
    }

    public function testDuplicatesAreIgnoredAndMonthBoundariesAreConsecutive(): void
    {
        $streak = StreakCalculator::analyse(['2026-09-30', '2026-09-30', '2026-10-01'], '2026-10-01');

        $this->assertSame(2, $streak['current']);
    }
}
