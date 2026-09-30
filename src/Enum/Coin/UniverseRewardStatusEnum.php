<?php

declare(strict_types=1);

namespace App\Enum\Coin;

enum UniverseRewardStatusEnum: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::PAID => 'Versée',
            self::FAILED => 'Échec',
        };
    }
}
