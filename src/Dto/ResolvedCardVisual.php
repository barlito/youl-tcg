<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\CardFrameEnum;
use App\Enum\Card\CardNameFontEnum;

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
        // Foil zoom percent (100 = cover); null = the preset's own sizing.
        public ?int $foilSize = null,
        // CSS frame variant, never null: frames are on by default (YOUL) and
        // NONE means the artwork carries its own baked frame.
        public CardFrameEnum $frame = CardFrameEnum::YOUL,
        // Font of the frame's name; null = frame.css default (Pirata One).
        public ?CardNameFontEnum $nameFont = null,
        // Neon inner-line gradient overrides; null = frame.css defaults.
        public ?string $frameLineStart = null,
        public ?string $frameLineEnd = null,
    ) {
    }

    /**
     * The frame variant to render, null when the card renders frameless.
     */
    public function frameVariant(): ?string
    {
        return CardFrameEnum::NONE === $this->frame ? null : $this->frame->value;
    }

    public function hasMask(): bool
    {
        return null !== $this->maskUrl;
    }

    /**
     * Value for the --imgsize CSS custom property (foil background-size).
     */
    public function foilSizeCss(): ?string
    {
        return match (true) {
            null === $this->foilSize => null,
            $this->foilSize >= 100 => 'cover',
            default => $this->foilSize . '%',
        };
    }
}
