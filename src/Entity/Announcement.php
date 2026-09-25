<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Notification\NotificationTypeEnum;
use App\Repository\AnnouncementRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin send log: one row per announcement or booster code notification
 * sent from the back-office (who, when, to whom). The player-side entries
 * live in Notification.
 */
#[ORM\Entity(repositoryClass: AnnouncementRepository::class)]
#[ORM\Index(name: 'idx_announcement_created', columns: ['created_at'])]
class Announcement
{
    use IdUuidTrait;

    /**
     * Empty for a broadcast.
     *
     * @var Collection<int, DiscordUser>
     */
    #[ORM\ManyToMany(targetEntity: DiscordUser::class)]
    #[ORM\JoinTable(name: 'announcement_recipient')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(referencedColumnName: 'discord_id', onDelete: 'CASCADE')]
    private Collection $recipients;

    /**
     * @param list<DiscordUser> $recipients empty = every player
     */
    public function __construct(
        #[ORM\Column(length: 40, enumType: NotificationTypeEnum::class)]
        private NotificationTypeEnum $type,
        #[ORM\Column(length: 150)]
        private string $title,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $message,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $link,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: true, onDelete: 'SET NULL')]
        private ?DiscordUser $author,
        array $recipients,
        #[ORM\Column]
        private int $sentCount,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        /** What was sent along, e.g. the code batch (never a single-use code). */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $context = null,
    ) {
        $this->recipients = new ArrayCollection($recipients);
    }

    public function getType(): NotificationTypeEnum
    {
        return $this->type;
    }

    public function getTypeLabel(): string
    {
        return NotificationTypeEnum::BOOSTER_CODE === $this->type ? 'Code booster' : 'Annonce';
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getAuthor(): ?DiscordUser
    {
        return $this->author;
    }

    public function isBroadcast(): bool
    {
        return $this->recipients->isEmpty();
    }

    /**
     * @return Collection<int, DiscordUser>
     */
    public function getRecipients(): Collection
    {
        return $this->recipients;
    }

    /**
     * Back-office summary of the target: "Tous les joueurs" or the names.
     */
    public function getTargetLabel(): string
    {
        if ($this->isBroadcast()) {
            return 'Tous les joueurs';
        }

        $names = $this->recipients->map(static fn (DiscordUser $user): string => $user->getUsername())->toArray();

        return \sprintf('Sélection (%d) : %s', \count($names), implode(', ', $names));
    }

    /**
     * Notifications actually stored: 1 for a broadcast, one per player otherwise.
     */
    public function getSentCount(): int
    {
        return $this->sentCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }
}
