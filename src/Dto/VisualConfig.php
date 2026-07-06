<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Card\CardEffectEnum;

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
        );
    }

    /**
     * @return array<string, string> only the set fields, ready for JSON storage
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'glow' => $this->glow,
                'borderColor' => $this->borderColor,
                'cssClass' => $this->cssClass,
                'holoEffect' => $this->holoEffect?->value,
            ],
            static fn (?string $value): bool => null !== $value,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->glow
            && null === $this->borderColor
            && null === $this->cssClass
            && !$this->holoEffect instanceof CardEffectEnum;
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
