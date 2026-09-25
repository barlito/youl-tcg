<?php

declare(strict_types=1);

namespace App\Enum\Notification;

/**
 * Who an admin send goes to.
 */
enum NotificationTargetEnum: string
{
    /** Code batch only: generate without notifying anyone. */
    case NONE = 'none';

    /** One broadcast entry, every player (present and future) sees it. */
    case ALL = 'all';

    /** One personal entry per selected player. */
    case SELECTION = 'selection';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Ne pas notifier',
            self::ALL => 'Tous les joueurs',
            self::SELECTION => 'Une sélection de joueurs',
        };
    }
}
