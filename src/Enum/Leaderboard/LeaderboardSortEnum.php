<?php

declare(strict_types=1);

namespace App\Enum\Leaderboard;

use App\Dto\LeaderboardEntry;

// Leaderboard ranking (`?tri=`): own metric first, then the completion order as tiebreak
enum LeaderboardSortEnum: string
{
    case COMPLETION = 'completion';
    case HOLOS = 'holos';
    case PACKS = 'packs';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETION => 'Complétion',
            self::HOLOS => 'Holos',
            self::PACKS => 'Packs ouverts',
        };
    }

    public function lead(): string
    {
        return match ($this) {
            self::COMPLETION => 'Classés par cartes distinctes trouvées.',
            self::HOLOS => 'Classés par copies holo possédées.',
            self::PACKS => 'Classés par packs ouverts.',
        };
    }

    public function metric(LeaderboardEntry $entry): int
    {
        return match ($this) {
            self::COMPLETION => $entry->distinctCards,
            self::HOLOS => $entry->holoCards,
            self::PACKS => $entry->openedBoosters,
        };
    }
}
