<?php

declare(strict_types=1);

namespace App\Enum\Entity;

/**
 * Values must match the card type CSS classes in assets/styles/cards/base.css.
 */
enum CardTypeEnum: string
{
    case WATER = 'water';
    case FIRE = 'fire';
    case GRASS = 'grass';
    case LIGHTNING = 'lightning';
    case PSYCHIC = 'psychic';
    case FIGHTING = 'fighting';
    case DARKNESS = 'darkness';
    case METAL = 'metal';
    case DRAGON = 'dragon';
    case FAIRY = 'fairy';
}
