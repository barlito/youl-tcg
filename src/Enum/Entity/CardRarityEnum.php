<?php

declare(strict_types=1);

namespace App\Enum\Entity;

enum CardRarityEnum: string
{
    case COMMON = 'common';
    case UNCOMMON = 'uncommon';
    case RARE = 'rare';
    case LEGENDARY = 'legendary';

    /**
     * @return list<self> rarities ordered from least to most rare
     */
    public static function ascending(): array
    {
        return [self::COMMON, self::UNCOMMON, self::RARE, self::LEGENDARY];
    }

    /**
     * Ascending rarity rank (0 = COMMON … 3 = LEGENDARY), the single source
     * for every rarity sort. Must stay consistent with ascending().
     */
    public function rank(): int
    {
        return match ($this) {
            self::COMMON => 0,
            self::UNCOMMON => 1,
            self::RARE => 2,
            self::LEGENDARY => 3,
        };
    }

    /**
     * Player-facing French label, consumed by PHP and Twig alike.
     */
    public function label(): string
    {
        return match ($this) {
            self::COMMON => 'Commune',
            self::UNCOMMON => 'Peu commune',
            self::RARE => 'Rare',
            self::LEGENDARY => 'Légendaire',
        };
    }

    /**
     * usort comparator for the catalog/pokédex order: rarest first. Name
     * tiebreaks stay at the call sites.
     */
    public static function compareRarestFirst(self $a, self $b): int
    {
        return $b->rank() <=> $a->rank();
    }
}
