<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

use App\Enum\Admin\EconomyPeriodEnum;

final readonly class StatsContext
{
    /**
     * @param list<string> $days Europe/Paris days of the period, Y-m-d, oldest first
     */
    public function __construct(
        public EconomyPeriodEnum $period,
        public \DateTimeImmutable $now,
        public \DateTimeImmutable $startDay,
        public string $since,
        public array $days,
        public string $today,
    ) {
    }
}
