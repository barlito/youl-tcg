<?php

declare(strict_types=1);

namespace App\Enum\Notification;

/**
 * What a notification is about. Only the type and a JSON payload are stored:
 * the text and the (internal) link are rendered server-side from them
 * (NotificationRenderer), never persisted as HTML.
 */
enum NotificationTypeEnum: string
{
    /** Broadcast: a player pulled a one-of-one. Never names the card. */
    case UNIQUE_PULLED = 'unique_pulled';

    /** A streak milestone reward is waiting to be picked. */
    case STREAK_REWARD_AVAILABLE = 'streak_reward_available';

    /** Boosters landed in the inventory outside the daily claim. */
    case BOOSTER_CREDITED = 'booster_credited';

    /** A player sent the recipient a trade offer (payload: playerName, playerId). */
    case TRADE_RECEIVED = 'trade_received';

    /** The recipient's offer was accepted (payload: playerName, playerId). */
    case TRADE_ACCEPTED = 'trade_accepted';

    /** The recipient's offer was refused (payload: playerName, playerId). */
    case TRADE_REFUSED = 'trade_refused';

    case ANNOUNCEMENT = 'announcement';
    case BOOSTER_CODE = 'booster_code';
}
