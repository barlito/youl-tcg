<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Card\CardEffectEnum;

/**
 * The visual properties of a card after the resolution cascade
 * (card -> extension -> system default), ready to be consumed by
 * CardComponent. URLs are public asset paths; colours/classes are null when
 * nothing overrides the system default (rarity glow, no preset).
 */
final readonly class ResolvedCardVisual
{
    public function __construct(
        public ?string $foilUrl = null,
        public ?string $maskUrl = null,
        public ?string $glow = null,
        public ?string $borderColor = null,
        public ?string $cssClass = null,
        public ?CardEffectEnum $holoEffect = null,
    ) {
    }

    public function hasMask(): bool
    {
        return null !== $this->maskUrl;
    }
}
