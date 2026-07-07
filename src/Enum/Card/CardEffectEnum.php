<?php

declare(strict_types=1);

namespace App\Enum\Card;

/**
 * Holo effect preset selected per card / extension (visual-config cascade).
 *
 * Each preset maps to a single CSS class (`holo--<value>`) defined in
 * assets/styles/cards/holo-presets.css, where it is a FAITHFUL PORT of one
 * poke-holo.simey.me card-type recipe (amazing-rare / regular / cosmos /
 * trainer-full-art). The preset fully drives
 * the shine/glare layers (it does NOT read the --holo-* knobs, which only tune
 * the rarity fallback recipe in holo.css for cards without a preset).
 * Resolved through the cascade: card override -> extension -> null (= pure
 * rarity recipe, no preset).
 */
enum CardEffectEnum: string
{
    case SHINE = 'shine';
    case BASIC = 'basic';
    case COSMOS = 'cosmos';
    case TRAINER = 'trainer';

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
            self::SHINE => 'Shine (amazing rare)',
            self::BASIC => 'Basic holo (regular holo)',
            self::COSMOS => 'Cosmos holo (galaxy)',
            self::TRAINER => 'Trainer / full-art',
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
