<?php

declare(strict_types=1);

namespace App\Dto\Admin;

final readonly class WeeklyRecycleStats
{
    /**
     * @param string $weekStart Monday of the Europe/Paris week, Y-m-d
     */
    public function __construct(
        public string $weekStart,
        public int $operations,
        public int $points,
        public int $boosters,
    ) {
    }
}
