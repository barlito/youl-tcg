<?php

declare(strict_types=1);

namespace App\Enum\Card;

/**
 * Font of the card name rendered by the CSS frame (--frame-name-font).
 * Curated list: every family here must be loaded by base.html.twig (Google
 * Fonts link) — an arbitrary font string would silently fall back. Resolved
 * through the visual-config cascade: card override -> extension -> Space
 * Grotesk (the frame.css default).
 */
enum CardNameFontEnum: string
{
    case SPACE_GROTESK = 'space-grotesk';
    case PIRATA_ONE = 'pirata-one';

    /**
     * CSS font-family stack for the --frame-name-font custom property.
     */
    public function cssFamily(): string
    {
        return match ($this) {
            self::SPACE_GROTESK => "'Space Grotesk', sans-serif",
            self::PIRATA_ONE => "'Pirata One', serif",
        };
    }

    /**
     * Human-readable label for the back office.
     */
    public function label(): string
    {
        return match ($this) {
            self::SPACE_GROTESK => 'Space Grotesk (défaut du site)',
            self::PIRATA_ONE => 'Pirata One (blackletter)',
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
