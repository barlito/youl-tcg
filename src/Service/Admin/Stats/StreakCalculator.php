<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

final class StreakCalculator
{
    /**
     * Same rule as OpeningStreakService: a run ending today or yesterday is running, an older one is dead.
     *
     * @param list<string> $days distinct opening days (Y-m-d, Europe/Paris), any order
     *
     * @return array{current: int, best: int, startedOn: string|null, openedToday: bool}
     */
    public static function analyse(array $days, string $today): array
    {
        $days = array_values(array_unique($days));
        sort($days);

        $best = 0;
        $run = 0;
        $runStart = null;
        $previous = null;

        foreach ($days as $day) {
            if (null !== $previous && RetentionCalculator::addDays($previous, 1) === $day) {
                ++$run;
            } else {
                $run = 1;
                $runStart = $day;
            }

            $best = max($best, $run);
            $previous = $day;
        }

        $yesterday = RetentionCalculator::addDays($today, -1);
        $running = null !== $previous && ($previous === $today || $previous === $yesterday);

        return [
            'current' => $running ? $run : 0,
            'best' => $best,
            'startedOn' => $running ? $runStart : null,
            'openedToday' => $previous === $today,
        ];
    }
}
