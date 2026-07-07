<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\BoosterOpening;

/**
 * Result of a booster opening: the persisted (aggregated) audit entity, plus
 * the drawn slots in their TRUE draw order — replacements of claimed uniques
 * included. The audit rows aggregate duplicates per card, so the slot order
 * only lives here: it is what a faithful reveal must follow.
 */
final readonly class BoosterOpeningResult
{
    /**
     * @param list<DrawnCard> $drawnCards
     */
    public function __construct(
        public BoosterOpening $opening,
        public array $drawnCards,
    ) {
    }
}
