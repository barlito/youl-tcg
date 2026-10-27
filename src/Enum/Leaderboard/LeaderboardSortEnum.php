<?php

declare(strict_types=1);

namespace App\Enum\Leaderboard;

use App\Dto\LeaderboardEntry;

/**
 * Leaderboard ranking (url `?tri=`). Each one ranks on its own metric first,
 * then falls back to the completion order (distinct, copies, username, id) so
 * the ranking stays deterministic.
 */
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

    /**
     * Primary metric of an entry for this ranking (desc).
     */
    public function metric(LeaderboardEntry $entry): int
    {
        return match ($this) {
            self::COMPLETION => $entry->distinctCards,
            self::HOLOS => $entry->holoCards,
            self::PACKS => $entry->openedBoosters,
        };
    }
}
