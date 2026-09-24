<?php

declare(strict_types=1);

namespace App\Enum\Admin;

/**
 * Every way a booster lands in a player's inventory. Each case maps to one
 * branch of the channel query in EconomyStatsProvider.
 */
enum BoosterChannelEnum: string
{
    case DAILY_CLAIM = 'claim';
    case CODE = 'code';
    case STREAK = 'streak';
    case RECYCLE = 'recycle';

    public function label(): string
    {
        return match ($this) {
            self::DAILY_CLAIM => 'Claim quotidien',
            self::CODE => 'Codes',
            self::STREAK => 'Récompenses de série',
            self::RECYCLE => 'Recyclage',
        };
    }
}
