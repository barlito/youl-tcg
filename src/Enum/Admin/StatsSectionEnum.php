<?php

declare(strict_types=1);

namespace App\Enum\Admin;

enum StatsSectionEnum: string
{
    case META = 'meta';
    case CATALOGUE = 'catalogue';
    case BOOSTERS = 'boosters';
    case DRAWS = 'draws';
    case PLAYERS = 'players';
    case ACTIVITY = 'activity';
    case ECONOMY = 'economy';
    case MARKET = 'market';
    case TRADES = 'trades';
    case RECYCLING = 'recycling';
    case FUSION = 'fusion';
    case CODES = 'codes';
    case STREAKS = 'streaks';
    case UNIVERSE_REWARDS = 'universeRewards';
    case WISHLIST = 'wishlist';
    case NOTIFICATIONS = 'notifications';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $section): string => $section->value, self::cases());
    }
}
