<?php

declare(strict_types=1);

namespace App\Enum\Collection;

// Collection `?tri=`: UNIVERSE keeps the per-universe sections, the others flatten the grid
enum CollectionSortEnum: string
{
    case UNIVERSE = 'univers';
    case RARITY = 'rarete';
    case NAME = 'nom';
    case QUANTITY = 'quantite';

    public function label(): string
    {
        return match ($this) {
            self::UNIVERSE => 'Univers',
            self::RARITY => 'Rareté',
            self::NAME => 'Nom',
            self::QUANTITY => 'Quantité',
        };
    }
}
