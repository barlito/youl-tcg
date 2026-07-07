<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\FoilTextureEnum;

/**
 * Visual customisation of a card: ambient glow colour, border accent colour,
 * an extra CSS class and the holo preset. Configured at the Extension level
 * and overridable per Card; every field is independently nullable so a card
 * override can set the glow while inheriting the rest of the extension's
 * configuration.
 *
 * Persisted as a JSON map; a null/absent field falls through the resolution
 * cascade (card override -> extension config -> system default).
 */
final readonly class VisualConfig
{
    public function __construct(
        public ?string $glow = null,
        public ?string $borderColor = null,
        public ?string $cssClass = null,
        // Holo effect preset (holo-presets.css); since the rarity recipes were
        // dropped, presets are the ONLY holo rendering path.
        public ?CardEffectEnum $holoEffect = null,
        // Bundled foil texture (library) — an uploaded foil always wins over it.
        public ?FoilTextureEnum $foilTexture = null,
        // Card frame: the card name (top-left, no background) + a custom
        // gradient border rendered as DOM layers ABOVE the holo effect. The
        // flag is meant per card (full-art artworks with baked-in text keep it
        // off); colours/gradients are free CSS values, defaulted per extension.
        public ?bool $showFrame = null,
        public ?string $nameColor = null,
        public ?string $frameGradient = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            glow: self::stringOrNull($data['glow'] ?? null),
            borderColor: self::stringOrNull($data['borderColor'] ?? null),
            cssClass: self::stringOrNull($data['cssClass'] ?? null),
            // Invalid / unknown preset names fall through to null (no preset).
            holoEffect: CardEffectEnum::tryFromName(self::stringOrNull($data['holoEffect'] ?? null)),
            foilTexture: FoilTextureEnum::tryFromName(self::stringOrNull($data['foilTexture'] ?? null)),
            showFrame: self::boolOrNull($data['showFrame'] ?? null),
            nameColor: self::stringOrNull($data['nameColor'] ?? null),
            frameGradient: self::stringOrNull($data['frameGradient'] ?? null),
        );
    }

    /**
     * @return array<string, string|bool> only the set fields, ready for JSON storage
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'glow' => $this->glow,
                'borderColor' => $this->borderColor,
                'cssClass' => $this->cssClass,
                'holoEffect' => $this->holoEffect?->value,
                'foilTexture' => $this->foilTexture?->value,
                'showFrame' => $this->showFrame,
                'nameColor' => $this->nameColor,
                'frameGradient' => $this->frameGradient,
            ],
            static fn (string | bool | null $value): bool => null !== $value,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->glow
            && null === $this->borderColor
            && null === $this->cssClass
            && !$this->holoEffect instanceof CardEffectEnum
            && !$this->foilTexture instanceof FoilTextureEnum
            && null === $this->showFrame
            && null === $this->nameColor
            && null === $this->frameGradient;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return \is_bool($value) ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
