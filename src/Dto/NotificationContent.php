<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What a notification says, rendered from its type + payload: plain text
 * only (escaped wherever it is displayed) and an internal path, or no link.
 */
final readonly class NotificationContent
{
    public function __construct(
        public string $icon,
        public string $text,
        public ?string $link,
        public ?string $body = null,
    ) {
    }
}
