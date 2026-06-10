<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Card;
use App\Enum\Entity\CardRarityEnum;

/**
 * A single card drawn from a booster slot. The rarity is the effective one:
 * it may differ from the slot's rolled rarity when the pool had no card of
 * that tier and the drawer fell back to another one.
 */
final readonly class DrawnCard
{
    public function __construct(
        public Card $card,
        public CardRarityEnum $rarity,
        public bool $holo,
    ) {
    }
}
