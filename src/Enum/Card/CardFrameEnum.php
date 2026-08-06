<?php

declare(strict_types=1);

namespace App\Enum\Card;

/**
 * CSS frame variant drawn over the artwork by CardComponent (name, extension
 * wordmark, watermark — recipes in assets/styles/cards/frame.css). Resolved
 * through the visual-config cascade: card override -> extension -> YOUL
 * (frames are on by default; NONE opts a set with frames already baked in
 * its artworks out).
 */
enum CardFrameEnum: string
{
    case YOUL = 'youl';
    case PLATE = 'plate';
    case MINIMAL = 'minimal';
    case NONE = 'none';

    /**
     * Human-readable label for the back office.
     */
    public function label(): string
    {
        return match ($this) {
            self::YOUL => 'Youl (cadre de référence)',
            self::PLATE => 'Plate (bandeau nom en bas)',
            self::MINIMAL => 'Minimal (anneau + nom centré)',
            self::NONE => 'Aucun (cadre baké dans l\'artwork)',
        };
    }

    /**
     * Parses an untrusted value, returning null instead of throwing.
     */
    public static function tryFromName(?string $value): ?self
    {
        if (null === $value) {
            return null;
        }

        return self::tryFrom($value);
    }
}
