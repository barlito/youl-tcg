<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Visual customisation of a card: ambient glow colour, border accent colour
 * and an extra CSS class. Configured at the Extension level and overridable
 * per Card; every field is independently nullable so a card override can set
 * the glow while inheriting the rest of the extension's configuration.
 *
 * Persisted as a JSON map; a null/absent field falls through the resolution
 * cascade (card override -> extension config -> system default keyed by
 * rarity in holo.css).
 */
final readonly class VisualConfig
{
    public function __construct(
        public ?string $glow = null,
        public ?string $borderColor = null,
        public ?string $cssClass = null,
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
            ],
            static fn (?string $value): bool => null !== $value,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->glow && null === $this->borderColor && null === $this->cssClass;
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
