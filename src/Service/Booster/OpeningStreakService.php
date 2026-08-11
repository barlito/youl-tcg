<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Dto\OpeningStreak;
use App\Entity\DiscordUser;
use App\Repository\BoosterOpeningRepository;
use Psr\Clock\ClockInterface;

/**
 * Computes the daily opening streak from BoosterOpening rows — a query, no
 * mutable counter, like the daily claim quota. Days are Europe/Paris calendar
 * days; the consecutive run is walked in PHP over the distinct-day list.
 */
final readonly class OpeningStreakService
{
    private const string TIMEZONE = 'Europe/Paris';

    /**
     * Distinct days fetched per player (~10 years of daily play). Bounds
     * memory without ever truncating a realistic series: a window shorter
     * than the series would shift its computed start date every day and
     * re-grant every milestone under a new series identity.
     */
    private const int MAX_DISTINCT_DAYS = 3650;

    public function __construct(
        private BoosterOpeningRepository $boosterOpeningRepository,
        private ClockInterface $clock,
    ) {
    }

    public function getStreak(DiscordUser $discordUser): OpeningStreak
    {
        $days = $this->boosterOpeningRepository->findDistinctOpeningDays($discordUser, self::TIMEZONE, self::MAX_DISTINCT_DAYS);

        if ([] === $days) {
            return new OpeningStreak(0, false);
        }

        $today = $this->clock->now()
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->format('Y-m-d')
        ;
        $openedToday = $today === $days[0];

        // the running series ends today, or yesterday (today's opening still pending)
        $anchor = $openedToday ? $today : $this->previousDay($today);

        if ($days[0] !== $anchor) {
            return new OpeningStreak(0, false);
        }

        $length = 0;
        $seriesStartedOn = $anchor;
        $expected = $anchor;

        foreach ($days as $day) {
            if ($day !== $expected) {
                break;
            }

            ++$length;
            $seriesStartedOn = $day;
            $expected = $this->previousDay($expected);
        }

        return new OpeningStreak($length, $openedToday, $seriesStartedOn);
    }

    /**
     * Pure calendar arithmetic on Y-m-d strings: immune to DST shifts.
     */
    private function previousDay(string $day): string
    {
        return new \DateTimeImmutable($day)->modify('-1 day')->format('Y-m-d');
    }
}
