<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DiscordUser;
use App\Repository\BoosterOpeningRepository;
use App\Service\Booster\OpeningStreakService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OpeningStreakServiceTest extends TestCase
{
    public function testNoOpeningMeansNoStreak(): void
    {
        $streak = $this->streakFor([], now: '2026-08-09 12:00:00');

        $this->assertSame(0, $streak->length);
        $this->assertFalse($streak->openedToday);
        $this->assertNull($streak->seriesStartedOn);
    }

    public function testAnOpeningTodayStartsASeriesOfOne(): void
    {
        $streak = $this->streakFor(['2026-08-09'], now: '2026-08-09 12:00:00');

        $this->assertSame(1, $streak->length);
        $this->assertTrue($streak->openedToday);
        $this->assertSame('2026-08-09', $streak->seriesStartedOn);
    }

    public function testConsecutiveDaysEndingTodayAreCounted(): void
    {
        $streak = $this->streakFor(['2026-08-09', '2026-08-08', '2026-08-07'], now: '2026-08-09 12:00:00');

        $this->assertSame(3, $streak->length);
        $this->assertTrue($streak->openedToday);
        $this->assertSame('2026-08-07', $streak->seriesStartedOn);
    }

    public function testASeriesEndingYesterdayStillCountsButIsNotFedToday(): void
    {
        $streak = $this->streakFor(['2026-08-08', '2026-08-07'], now: '2026-08-09 12:00:00');

        $this->assertSame(2, $streak->length);
        $this->assertFalse($streak->openedToday);
        $this->assertTrue($streak->isAtRisk());
        $this->assertSame('2026-08-07', $streak->seriesStartedOn);
    }

    public function testASeriesEndedBeforeYesterdayIsDead(): void
    {
        $streak = $this->streakFor(['2026-08-07', '2026-08-06'], now: '2026-08-09 12:00:00');

        $this->assertSame(0, $streak->length);
        $this->assertNull($streak->seriesStartedOn);
    }

    public function testAGapStopsTheCountEvenWithOlderOpenings(): void
    {
        $streak = $this->streakFor(['2026-08-09', '2026-08-08', '2026-08-06', '2026-08-05'], now: '2026-08-09 12:00:00');

        $this->assertSame(2, $streak->length);
        $this->assertSame('2026-08-08', $streak->seriesStartedOn);
    }

    public function testTodayIsAParisCalendarDayInWinter(): void
    {
        // 2026-01-15 23:30 UTC is already 2026-01-16 00:30 in Paris (UTC+1):
        // an opening logged on the Paris 16th must count as "today".
        $streak = $this->streakFor(['2026-01-16'], now: '2026-01-15 23:30:00');

        $this->assertSame(1, $streak->length);
        $this->assertTrue($streak->openedToday);
    }

    public function testTodayIsAParisCalendarDayInSummer(): void
    {
        // 2026-06-10 22:30 UTC = 2026-06-11 00:30 Paris (UTC+2): a series
        // ending on the Paris 10th is "yesterday's", at risk but alive.
        $streak = $this->streakFor(['2026-06-10'], now: '2026-06-10 22:30:00');

        $this->assertSame(1, $streak->length);
        $this->assertFalse($streak->openedToday);
        $this->assertTrue($streak->isAtRisk());
    }

    /**
     * @param list<string> $distinctDays
     */
    private function streakFor(array $distinctDays, string $now): \App\Dto\OpeningStreak
    {
        $repository = $this->createMock(BoosterOpeningRepository::class);
        $repository->expects($this->once())
            ->method('findDistinctOpeningDays')
            ->with($this->anything(), 'Europe/Paris', $this->greaterThan(0))
            ->willReturn($distinctDays)
        ;

        return new OpeningStreakService($repository, new MockClock($now, 'UTC'))
            ->getStreak(new DiscordUser())
        ;
    }
}
