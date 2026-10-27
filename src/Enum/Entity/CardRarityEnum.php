<?php

declare(strict_types=1);

namespace App\Enum\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum CardRarityEnum: string implements TranslatableInterface
{
    case COMMON = 'common';
    case UNCOMMON = 'uncommon';
    case RARE = 'rare';
    case LEGENDARY = 'legendary';

    /**
     * Mark of a 1/1 unique (a flag, not a tier): distinct from every rarity shape, always next to the « 1/1 » text.
     */
    public const string UNIQUE_ICON = '♛';

    /**
     * @return list<self> rarities ordered from least to most rare
     */
    public static function ascending(): array
    {
        return [self::COMMON, self::UNCOMMON, self::RARE, self::LEGENDARY];
    }

    /**
     * Ascending rank (0 = COMMON … 3 = LEGENDARY); must stay consistent with ascending().
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
     * French label in Symfony forms (EnumType) and EasyAdmin choice fields/filters.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }

    /**
     * Shape that doubles the rarity colour (never colour alone).
     */
    public function icon(): string
    {
        return match ($this) {
            self::COMMON => '●',
            self::UNCOMMON => '◆',
            self::RARE => '★',
            self::LEGENDARY => '★★',
        };
    }

    /**
     * Rarest-first usort comparator; name tiebreaks stay at the call sites.
     */
    public static function compareRarestFirst(self $a, self $b): int
    {
        return $b->rank() <=> $a->rank();
    }

    /**
     * Recycling value of one normal copy of this rarity. The whole recycle
     * scale lives here: holoRecyclePoints() derives from it.
     */
    public function recyclePoints(): int
    {
        return match ($this) {
            self::COMMON => 1,
            self::UNCOMMON => 2,
            self::RARE => 3,
            self::LEGENDARY => 5,
        };
    }

    /**
     * Recycling value of one holo copy: the rarity scale plus a flat bonus.
     */
    public function holoRecyclePoints(): int
    {
        return $this->recyclePoints() + 1;
    }
}
