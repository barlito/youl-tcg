<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Booster;

/**
 * One booster's personal luck sheet: what the player actually pulled from
 * THIS pack versus the drop rates the pack advertises today.
 */
final readonly class BoosterLuckStats
{
    /**
     * @param array<string, array{observed: float, expected: float}> $rarityRows rarity value => shares in %, ascending rarity order
     */
    public function __construct(
        public Booster $booster,
        public int $openingCount,
        public int $cardCount,
        public float $observedHoloRate,
        public float $expectedHoloRate,
        public array $rarityRows,
    ) {
    }
}
