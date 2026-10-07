<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

final class RetentionCalculator
{
    public const array OFFSETS = [1, 7, 30];

    /**
     * Weekly cohorts by first active day; retained at J+N = active exactly that day, eligible once it is reached.
     *
     * @param array<string, list<string>> $daysByPlayer distinct active days (Y-m-d) per player
     *
     * @return list<array{week: string, size: int, retention: array<string, array{eligible: int, retained: int, ratePercent: float|null}>}>
     */
    public static function cohorts(array $daysByPlayer, string $today, ?string $fromWeek = null): array
    {
        $sizes = [];
        $eligible = [];
        $retained = [];

        foreach ($daysByPlayer as $days) {
            if ([] === $days) {
                continue;
            }

            $first = min($days);
            $week = self::mondayOf($first);

            if (null !== $fromWeek && $week < $fromWeek) {
                continue;
            }

            $sizes[$week] = ($sizes[$week] ?? 0) + 1;
            $active = array_flip($days);

            foreach (self::OFFSETS as $offset) {
                $target = self::addDays($first, $offset);

                if ($target > $today) {
                    continue;
                }

                $eligible[$week][$offset] = ($eligible[$week][$offset] ?? 0) + 1;
                $retained[$week][$offset] = ($retained[$week][$offset] ?? 0) + (isset($active[$target]) ? 1 : 0);
            }
        }

        ksort($sizes);
        $cohorts = [];

        foreach ($sizes as $week => $size) {
            $retention = [];

            foreach (self::OFFSETS as $offset) {
                $eligibleCount = $eligible[$week][$offset] ?? 0;
                $retainedCount = $retained[$week][$offset] ?? 0;
                $retention['d' . $offset] = [
                    'eligible' => $eligibleCount,
                    'retained' => $retainedCount,
                    'ratePercent' => $eligibleCount > 0 ? round($retainedCount / $eligibleCount * 100, 2) : null,
                ];
            }

            $cohorts[] = ['week' => $week, 'size' => $size, 'retention' => $retention];
        }

        return $cohorts;
    }

    public static function mondayOf(string $day): string
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('UTC'))->modify('monday this week')->format('Y-m-d');
    }

    public static function addDays(string $day, int $days): string
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('UTC'))->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }
}
