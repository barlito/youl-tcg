<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Notification\NotificationTypeEnum;
use App\Repository\NotificationRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A notification center entry. A null recipient is a broadcast to every
 * player: its read state is per player, derived from
 * DiscordUser::$notificationsSeenAt (no row per player). Personal entries
 * carry their own readAt.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
// also serves broadcasts (recipient_id IS NULL): PG btrees index NULLs
#[ORM\Index(name: 'idx_notification_recipient_created', columns: ['recipient_id', 'created_at'])]
class Notification
{
    use IdUuidTrait;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: true, onDelete: 'CASCADE')]
        private ?DiscordUser $recipient,
        #[ORM\Column(length: 40, enumType: NotificationTypeEnum::class)]
        private NotificationTypeEnum $type,
        #[ORM\Column(type: Types::JSON)]
        private array $payload,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getRecipient(): ?DiscordUser
    {
        return $this->recipient;
    }

    public function isBroadcast(): bool
    {
        return !$this->recipient instanceof DiscordUser;
    }

    public function getType(): NotificationTypeEnum
    {
        return $this->type;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(\DateTimeImmutable $readAt): static
    {
        $this->readAt ??= $readAt;

        return $this;
    }

    /**
     * Unread for $viewer: personal entries by readAt, broadcasts against the
     * viewer's last "mark all as read" (or their sign-up when never done).
     */
    public function isUnreadFor(DiscordUser $viewer): bool
    {
        if (!$this->isBroadcast()) {
            return !$this->readAt instanceof \DateTimeImmutable;
        }

        return $this->createdAt > $viewer->getNotificationsSeenSince();
    }
}
