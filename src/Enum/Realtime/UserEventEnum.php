<?php

declare(strict_types=1);

namespace App\Enum\Realtime;

/**
 * Realtime events pushed to a player's private Mercure topic. The value is
 * the DOM event suffix: the browser redispatches them as `live-updates:<value>`.
 */
enum UserEventEnum: string
{
    /** Generic message shown as a toast (payload: message, optional link). */
    case TOAST = 'toast';

    /** Boosters or cards changed: pages showing the inventory re-render. */
    case INVENTORY_CHANGED = 'inventory-changed';
}
