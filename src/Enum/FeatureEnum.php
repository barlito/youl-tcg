<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Features that can be switched off from the admin (FeatureFlag rows).
 */
enum FeatureEnum: string
{
    case TRADES = 'trades';
    case RECYCLING = 'recycling';

    public function label(): string
    {
        return match ($this) {
            self::TRADES => 'Échanges entre joueurs',
            self::RECYCLING => 'Recyclage des doublons',
        };
    }
}
