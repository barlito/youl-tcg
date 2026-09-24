<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Card;
use App\Enum\Entity\CardRarityEnum;

/**
 * One card of an offer AS SEEN BY one reader. A card the reader does not own
 * carries no Card at all — only its rarity, deliberately kept so a blind offer
 * stays judgeable. The template therefore cannot leak a name it never receives.
 */
final readonly class TradeLineView
{
    public function __construct(
        public ?Card $card,
        public CardRarityEnum $rarity,
        public int $totalQuantity,
        public int $holoQuantity,
    ) {
    }

    public function isMasked(): bool
    {
        return !$this->card instanceof Card;
    }
}
