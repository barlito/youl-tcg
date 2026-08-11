<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\DiscordUser;

/**
 * One leaderboard row: a player and their collection aggregates. Uniques are a
 * COUNT only — which 1/1 cards someone holds is never exposed.
 */
final readonly class LeaderboardEntry
{
    public function __construct(
        public int $rank,
        public DiscordUser $user,
        public int $distinctCards,
        public int $totalCards,
        public int $holoCards,
        public int $uniqueCards,
        public int $openedBoosters,
        public int $completionPct,
    ) {
    }
}
