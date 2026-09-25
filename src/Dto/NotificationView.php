<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A notification ready to display: plain text (escaped by Twig) and an
 * internal path, both rendered server-side from type + payload.
 */
final readonly class NotificationView
{
    public function __construct(
        public ?string $id,
        public string $icon,
        public string $text,
        public string $link,
        public ?\DateTimeImmutable $createdAt,
        public bool $unread,
    ) {
    }
}
