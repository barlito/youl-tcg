<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * Drawn rarities (+ holo rate) over a period versus the configured rates of
 * the boosters actually opened, weighted by slot and opening count.
 */
final readonly class RarityComparison
{
    /**
     * @param list<RarityComparisonRow> $rows one per rarity tier, then the 1/1 bucket
     */
    public function __construct(
        public int $openingCount,
        public int $cardCount,
        public array $rows,
        public RarityComparisonRow $holo,
    ) {
    }
}
