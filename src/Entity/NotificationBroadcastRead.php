<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationBroadcastReadRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A broadcast opened by one player. Complements DiscordUser::$notificationsSeenAt,
 * which only moves on "mark all as read" (then these rows are purged).
 */
#[ORM\Entity(repositoryClass: NotificationBroadcastReadRepository::class)]
class NotificationBroadcastRead
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Notification $notification,
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false, onDelete: 'CASCADE')]
        private DiscordUser $discordUser,
        #[ORM\Column]
        private \DateTimeImmutable $readAt,
    ) {
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getReadAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }
}
