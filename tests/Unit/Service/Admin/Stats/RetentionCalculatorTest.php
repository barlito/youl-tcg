<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin\Stats;

use App\Service\Admin\Stats\RetentionCalculator;
use PHPUnit\Framework\TestCase;

final class RetentionCalculatorTest extends TestCase
{
    public function testPlayersAreGroupedByTheMondayOfTheirFirstDay(): void
    {
        $cohorts = RetentionCalculator::cohorts([
            'a' => ['2026-10-04'],
            'b' => ['2026-09-28', '2026-10-02'],
            'c' => ['2026-10-05'],
        ], '2026-10-20');

        $this->assertSame(['2026-09-28', '2026-10-05'], array_column($cohorts, 'week'));
        $this->assertSame([2, 1], array_column($cohorts, 'size'));
    }

    public function testRetentionIsMeasuredOnTheExactDayAndOnlyForEligiblePlayers(): void
    {
        $cohorts = RetentionCalculator::cohorts([
            'back-next-day' => ['2026-10-05', '2026-10-06', '2026-10-12'],
            'back-late' => ['2026-10-05', '2026-10-07'],
            'never' => ['2026-10-05'],
            'too-recent' => ['2026-10-11'],
        ], '2026-10-11');

        $first = $cohorts[0];
        $this->assertSame('2026-10-05', $first['week']);
        $this->assertSame(['eligible' => 3, 'retained' => 1, 'ratePercent' => 33.33], $first['retention']['d1']);
        $this->assertSame(['eligible' => 0, 'retained' => 0, 'ratePercent' => null], $first['retention']['d7'], 'J+7 of the 5th is the 12th: not reached yet');
        $this->assertSame(4, $first['size']);
        $this->assertSame(['eligible' => 0, 'retained' => 0, 'ratePercent' => null], $first['retention']['d30']);
    }

    public function testDayNIsReachedWhenItIsToday(): void
    {
        $cohorts = RetentionCalculator::cohorts(['a' => ['2026-10-05', '2026-10-12']], '2026-10-12');

        $this->assertSame(['eligible' => 1, 'retained' => 1, 'ratePercent' => 100.0], $cohorts[0]['retention']['d7']);
    }

    public function testCohortsBeforeTheRequestedWeekAreDropped(): void
    {
        $cohorts = RetentionCalculator::cohorts(['old' => ['2026-06-01'], 'new' => ['2026-10-06']], '2026-10-20', '2026-10-05');

        $this->assertSame(['2026-10-05'], array_column($cohorts, 'week'));
    }

    public function testPlayersWithoutActivityAreIgnored(): void
    {
        $this->assertSame([], RetentionCalculator::cohorts(['ghost' => []], '2026-10-20'));
    }

    public function testMondayOfASundayIsTheMondayBefore(): void
    {
        $this->assertSame('2026-09-28', RetentionCalculator::mondayOf('2026-10-04'));
        $this->assertSame('2026-10-05', RetentionCalculator::mondayOf('2026-10-05'));
        $this->assertSame('2026-12-28', RetentionCalculator::mondayOf('2027-01-01'));
    }

    public function testAddDaysCrossesMonthsAndYears(): void
    {
        $this->assertSame('2027-01-02', RetentionCalculator::addDays('2026-12-30', 3));
        $this->assertSame('2026-02-28', RetentionCalculator::addDays('2026-03-01', -1));
    }
}
