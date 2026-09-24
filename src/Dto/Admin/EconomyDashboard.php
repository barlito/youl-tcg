<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\Admin\EconomyPeriodEnum;

/**
 * Everything the admin dashboard renders for one period. Plain scalars and
 * DTOs only: the whole object is cached.
 */
final readonly class EconomyDashboard
{
    /**
     * @param list<string>                      $days                Europe/Paris days of the period, Y-m-d, oldest first
     * @param array<string, int>                $openingsPerDay      day => openings
     * @param array<string, int>                $activePlayersPerDay day => distinct active players
     * @param array<string, array<string, int>> $boostersPerChannel  channel value => day => boosters
     * @param list<WeeklyRecycleStats>          $weeklyRecycles
     */
    public function __construct(
        public EconomyPeriodEnum $period,
        public \DateTimeImmutable $generatedAt,
        public EconomyKpis $kpis,
        public array $days,
        public array $openingsPerDay,
        public array $activePlayersPerDay,
        public array $boostersPerChannel,
        public array $weeklyRecycles,
        public RarityComparison $rarityComparison,
    ) {
    }

    /**
     * @return array<string, int> channel value => boosters over the whole period
     */
    public function channelTotals(): array
    {
        return array_map(array_sum(...), $this->boostersPerChannel);
    }
}
