<?php

declare(strict_types=1);

namespace App\Exception\Notification;

/**
 * An admin send that must not go out. The message is French, admin-facing,
 * safe to flash as-is.
 */
final class NotificationRefusedException extends \RuntimeException
{
}
