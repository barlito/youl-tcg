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
        // Holo tuning (cascade card -> extension -> rarity default in holo.css).
        // Clamped to safe ranges so a BO value can never crame the artwork.
        public ?float $holoIntensity = null,
        public ?float $holoSaturation = null,
        public ?float $holoGlitter = null,
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
            holoIntensity: self::floatOrNull($data['holoIntensity'] ?? null, 0.0, 1.0),
            holoSaturation: self::floatOrNull($data['holoSaturation'] ?? null, 0.0, 3.0),
            holoGlitter: self::floatOrNull($data['holoGlitter'] ?? null, 0.0, 2.0),
        );
    }

    /**
     * @return array<string, string|float> only the set fields, ready for JSON storage
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'glow' => $this->glow,
                'borderColor' => $this->borderColor,
                'cssClass' => $this->cssClass,
                'holoIntensity' => $this->holoIntensity,
                'holoSaturation' => $this->holoSaturation,
                'holoGlitter' => $this->holoGlitter,
            ],
            static fn (string | float | null $value): bool => null !== $value,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->glow
            && null === $this->borderColor
            && null === $this->cssClass
            && null === $this->holoIntensity
            && null === $this->holoSaturation
            && null === $this->holoGlitter;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private static function floatOrNull(mixed $value, float $min, float $max): ?float
    {
        if (!\is_int($value) && !\is_float($value) && !(\is_string($value) && is_numeric($value))) {
            return null;
        }

        return max($min, min($max, (float) $value));
    }
}
