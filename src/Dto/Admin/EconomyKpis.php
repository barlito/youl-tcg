<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * Headline numbers of the admin dashboard. « Active » = at least one opening
 * or one daily claim; 7/30 days are Europe/Paris calendar days, today included.
 */
final readonly class EconomyKpis
{
    public function __construct(
        public int $registeredPlayers,
        public int $activePlayers7Days,
        public int $activePlayers30Days,
        public int $openingsToday,
        public int $openings7Days,
        public int $openingsTotal,
        public int $cardsDrawn,
        public int $holosDrawn,
        public int $uniquesDrawn,
        public int $uniquesTotal,
        public int $unopenedBoosters,
    ) {
    }
}
