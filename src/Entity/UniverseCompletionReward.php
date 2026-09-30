<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Coin\UniverseRewardStatusEnum;
use App\Repository\UniverseCompletionRewardRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: UniverseCompletionRewardRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_universe_reward_player_extension', columns: ['discord_user_id', 'extension_id'])]
#[ORM\Index(columns: ['status'])]
class UniverseCompletionReward
{
    use IdUuidTrait;
    use TimestampableEntity;

    private const string EXTERNAL_IDENTIFIER_PREFIX = 'ytcg:universe-reward:';

    #[ORM\Column(enumType: UniverseRewardStatusEnum::class)]
    private UniverseRewardStatusEnum $status = UniverseRewardStatusEnum::PENDING;

    #[ORM\Column(nullable: true)]
    private ?string $coinTransactionId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $discordUser,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Extension $extension,
        /** Whole coins, frozen when the universe was completed. */
        #[ORM\Column]
        private int $amount,
        #[ORM\Column]
        private \DateTimeImmutable $completedAt,
    ) {
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getStatus(): UniverseRewardStatusEnum
    {
        return $this->status;
    }

    public function isPaid(): bool
    {
        return UniverseRewardStatusEnum::PAID === $this->status;
    }

    public function getCoinTransactionId(): ?string
    {
        return $this->coinTransactionId;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getExternalIdentifier(): string
    {
        return self::EXTERNAL_IDENTIFIER_PREFIX . $this->id;
    }

    public function markPaid(?string $coinTransactionId, \DateTimeImmutable $at): void
    {
        $this->status = UniverseRewardStatusEnum::PAID;
        $this->coinTransactionId = $coinTransactionId;
        $this->paidAt = $at;
    }

    public function markFailed(): void
    {
        $this->status = UniverseRewardStatusEnum::FAILED;
    }
}
