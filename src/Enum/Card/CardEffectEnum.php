<?php

declare(strict_types=1);

namespace App\Enum\Card;

/**
 * Holo effect preset applied on top of the rarity-keyed recipe of holo.css.
 *
 * Each preset maps to a single CSS class (`holo--<value>`) defined in
 * assets/styles/cards/holo-presets.css; the class overrides the shine/glare
 * backgrounds while reusing the existing --holo-* knobs and pointer vars.
 * Resolved through the visual-config cascade (card override -> extension ->
 * null = pure rarity recipe).
 */
enum CardEffectEnum: string
{
    case SHINE = 'shine';
    case BASIC = 'basic';
    case REVERSE = 'reverse';
    case COSMOS = 'cosmos';
    case RAINBOW = 'rainbow';
    case SECRET = 'secret';

    /**
     * CSS class toggled on the .card element to activate the preset.
     */
    public function cssClass(): string
    {
        return 'holo--' . $this->value;
    }

    /**
     * Human-readable label for the back office.
     */
    public function label(): string
    {
        return match ($this) {
            self::SHINE => 'Shine (sober sheen)',
            self::BASIC => 'Basic holo (linear)',
            self::REVERSE => 'Reverse (artwork-dominant)',
            self::COSMOS => 'Cosmos (galaxy glitter)',
            self::RAINBOW => 'Rainbow (conic swirl)',
            self::SECRET => 'Secret rare (dense rainbow)',
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
