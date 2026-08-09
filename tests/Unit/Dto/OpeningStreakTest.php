<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\OpeningStreak;
use PHPUnit\Framework\TestCase;

final class OpeningStreakTest extends TestCase
{
    public function testReachedMilestonesAreTheMultiplesOfSevenUpToTheLength(): void
    {
        $this->assertSame([], new OpeningStreak(0, false)->reachedMilestones());
        $this->assertSame([], new OpeningStreak(6, true, '2026-08-04')->reachedMilestones());
        $this->assertSame([7], new OpeningStreak(7, true, '2026-08-03')->reachedMilestones());
        $this->assertSame([7], new OpeningStreak(13, true, '2026-07-28')->reachedMilestones());
        $this->assertSame([7, 14, 21], new OpeningStreak(21, true, '2026-07-20')->reachedMilestones());
    }

    public function testNextMilestoneIsTheUpcomingMultipleOfSeven(): void
    {
        $this->assertSame(7, new OpeningStreak(0, false)->nextMilestone());
        $this->assertSame(7, new OpeningStreak(6, true, '2026-08-04')->nextMilestone());
        $this->assertSame(14, new OpeningStreak(7, true, '2026-08-03')->nextMilestone());
        $this->assertSame(2, new OpeningStreak(12, true, '2026-07-29')->daysUntilNextMilestone());
    }

    public function testARunningSeriesWithoutAnOpeningTodayIsAtRisk(): void
    {
        $this->assertFalse(new OpeningStreak(0, false)->isAtRisk());
        $this->assertFalse(new OpeningStreak(3, true, '2026-08-07')->isAtRisk());
        $this->assertTrue(new OpeningStreak(3, false, '2026-08-06')->isAtRisk());
    }
}
