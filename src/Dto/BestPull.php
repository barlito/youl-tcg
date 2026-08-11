<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Card;
use App\Enum\Entity\CardRarityEnum;

/**
 * The player's proudest moment: the earliest pull of the rarest tier they
 * ever reached (their first legendary, once they have one).
 */
final readonly class BestPull
{
    public function __construct(
        public Card $card,
        public CardRarityEnum $rarity,
        public \DateTimeImmutable $openedAt,
        public bool $holo,
    ) {
    }
}
