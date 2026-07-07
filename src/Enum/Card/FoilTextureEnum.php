<?php

declare(strict_types=1);

namespace App\Enum\Card;

/**
 * Bundled foil textures (public/images/holo/poke/) assignable to a card or an
 * extension through the visual config — the premium look without uploading a
 * dedicated foil. An uploaded foil (card, then extension) always wins over a
 * library texture in the resolution cascade.
 */
enum FoilTextureEnum: string
{
    case ANCIENT = 'ancient';
    case GEOMETRIC = 'geometric';
    case VMAX = 'vmax';
    case TRAINER = 'trainer';

    public static function tryFromName(?string $name): ?self
    {
        return null !== $name ? self::tryFrom($name) : null;
    }

    /**
     * Public path of the texture, ready for the --foil CSS custom property.
     */
    public function url(): string
    {
        return '/images/holo/poke/' . match ($this) {
            self::ANCIENT => 'ancient.png',
            self::GEOMETRIC => 'geometric.png',
            self::VMAX => 'vmaxbg.jpg',
            self::TRAINER => 'trainerbg.png',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ANCIENT => 'Ancient (runes)',
            self::GEOMETRIC => 'Geometric (facettes)',
            self::VMAX => 'V-Max (éclats)',
            self::TRAINER => 'Trainer (iridescent)',
        };
    }
}
