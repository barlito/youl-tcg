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
    case UNIVERSE_REWARDS = 'universe_rewards';
    case FUSION = 'fusion';

    public function label(): string
    {
        return match ($this) {
            self::TRADES => 'Échanges entre joueurs',
            self::RECYCLING => 'Recyclage des doublons',
            self::UNIVERSE_REWARDS => 'Récompenses de complétion d\'univers',
            self::FUSION => 'Fusion des doublons',
        };
    }
}
