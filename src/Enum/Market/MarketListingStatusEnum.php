<?php

declare(strict_types=1);

namespace App\Enum\Market;

enum MarketListingStatusEnum: string
{
    case ACTIVE = 'active';
    case RESERVED_FOR_PURCHASE = 'reserved_for_purchase';
    case SOLD = 'sold';
    case WITHDRAWN = 'withdrawn';
    case INVALIDATED = 'invalidated';

    public function isEngaged(): bool
    {
        return self::ACTIVE === $this || self::RESERVED_FOR_PURCHASE === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'En vente',
            self::RESERVED_FOR_PURCHASE => 'Achat en cours',
            self::SOLD => 'Vendue',
            self::WITHDRAWN => 'Retirée',
            self::INVALIDATED => 'Invalidée',
        };
    }
}
