<?php

declare(strict_types=1);

namespace App\Enum\Entity;

enum CardRarityEnum: string
{
    case COMMON = 'common';
    case UNCOMMON = 'uncommon';
    case RARE = 'rare';
    case EPIC = 'epic';
    case LEGENDARY = 'legendary';

    /**
     * @return list<self> rarities ordered from least to most rare
     */
    public static function ascending(): array
    {
        return [self::COMMON, self::UNCOMMON, self::RARE, self::EPIC, self::LEGENDARY];
    }
}
