<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The visual properties of a card after the resolution cascade
 * (card -> extension -> system default), ready to be consumed by
 * CardComponent. URLs are public asset paths; colours/classes are null when
 * nothing overrides the rarity-keyed default of holo.css.
 */
final readonly class ResolvedCardVisual
{
    public function __construct(
        public ?string $foilUrl = null,
        public ?string $maskUrl = null,
        public ?string $glow = null,
        public ?string $borderColor = null,
        public ?string $cssClass = null,
        public ?float $holoIntensity = null,
        public ?float $holoSaturation = null,
        public ?float $holoGlitter = null,
    ) {
    }

    public function hasMask(): bool
    {
        return null !== $this->maskUrl;
    }
}
