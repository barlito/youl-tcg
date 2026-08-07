<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\CardFrameEnum;
use App\Enum\Card\CardNameFontEnum;
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
    // foilSize : zoom du foil en pourcentage de la largeur de la carte.
    // 100 = cover (pleine carte), en dessous = motif tilé de plus en plus
    // petit. En dehors de [MIN, MAX] (dont la position « Auto » du slider
    // admin, sous MIN) la valeur est ignorée → réglage du preset.
    public const int FOIL_SIZE_MIN = 10;
    public const int FOIL_SIZE_MAX = 100;
    public const int FOIL_SIZE_AUTO = 5;

    public function __construct(
        public ?string $glow = null,
        public ?string $borderColor = null,
        public ?string $cssClass = null,
        // Holo effect preset (holo-presets.css); since the rarity recipes were
        // dropped, presets are the ONLY holo rendering path.
        public ?CardEffectEnum $holoEffect = null,
        // Bundled foil texture (library) — an uploaded foil always wins over it.
        public ?FoilTextureEnum $foilTexture = null,
        // Foil zoom percent (FOIL_SIZE_MIN..FOIL_SIZE_MAX, 100 = cover);
        // null = the preset's own sizing.
        public ?int $foilSize = null,
        // CSS frame variant (frame.css); null = inherit, resolver defaults to YOUL.
        public ?CardFrameEnum $frame = null,
        // Font of the name drawn by the CSS frame; null = frame.css default.
        public ?CardNameFontEnum $nameFont = null,
        // Neon inner-line gradient of the youl frame (start/end colours);
        // null = the frame.css default gradient.
        public ?string $frameLineStart = null,
        public ?string $frameLineEnd = null,
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
            foilSize: self::foilSizeOrNull($data['foilSize'] ?? null),
            frame: CardFrameEnum::tryFromName(self::stringOrNull($data['frame'] ?? null)),
            nameFont: CardNameFontEnum::tryFromName(self::stringOrNull($data['nameFont'] ?? null)),
            frameLineStart: self::stringOrNull($data['frameLineStart'] ?? null),
            frameLineEnd: self::stringOrNull($data['frameLineEnd'] ?? null),
        );
    }

    /**
     * @return array<string, string|int> only the set fields, ready for JSON storage
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
                'foilSize' => $this->foilSize,
                'frame' => $this->frame?->value,
                'nameFont' => $this->nameFont?->value,
                'frameLineStart' => $this->frameLineStart,
                'frameLineEnd' => $this->frameLineEnd,
            ],
            static fn (string | int | null $value): bool => null !== $value,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->glow
            && null === $this->borderColor
            && null === $this->cssClass
            && !$this->holoEffect instanceof CardEffectEnum
            && !$this->foilTexture instanceof FoilTextureEnum
            && null === $this->foilSize
            && !$this->frame instanceof CardFrameEnum
            && !$this->nameFont instanceof CardNameFontEnum
            && null === $this->frameLineStart
            && null === $this->frameLineEnd;
    }

    public static function foilSizeOrNull(mixed $value): ?int
    {
        if (\is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!\is_int($value)) {
            return null;
        }

        return $value >= self::FOIL_SIZE_MIN && $value <= self::FOIL_SIZE_MAX ? $value : null;
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
