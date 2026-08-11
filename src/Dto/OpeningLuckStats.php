<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Personal draw statistics, entirely computed by SQL aggregates: what the
 * player opened, what came out, and how it compares to the advertised rates.
 */
final readonly class OpeningLuckStats
{
    /**
     * @param array<string, int>     $rarityCounts rarity value => copies pulled, ascending rarity order, zeros included
     * @param array<string, float>   $rarityShares rarity value => share of pulled copies in %
     * @param list<BoosterLuckStats> $boosters     most-opened boosters first
     */
    public function __construct(
        public int $openingCount,
        public int $cardCount,
        public int $holoCount,
        public float $holoRate,
        public array $rarityCounts,
        public array $rarityShares,
        public array $boosters,
        public ?BestPull $bestPull,
    ) {
    }
}
