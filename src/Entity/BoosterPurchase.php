<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Booster\BoosterPurchaseStatusEnum;
use App\Repository\BoosterPurchaseRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Audit and state of one booster bought with Youl Coin. The daily purchase
 * quota counts the pending and completed rows since midnight (Europe/Paris).
 */
#[ORM\Entity(repositoryClass: BoosterPurchaseRepository::class)]
#[ORM\Index(columns: ['discord_user_id', 'requested_at'])]
#[ORM\Index(columns: ['status'])]
class BoosterPurchase
{
    use IdUuidTrait;
    use TimestampableEntity;

    private const string EXTERNAL_IDENTIFIER_PREFIX = 'ytcg:booster-purchase:';

    #[ORM\Column(enumType: BoosterPurchaseStatusEnum::class)]
    private BoosterPurchaseStatusEnum $status = BoosterPurchaseStatusEnum::PENDING;

    #[ORM\Column(nullable: true)]
    private ?string $coinTransactionId = null;

    #[ORM\Column(nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $discordUser,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Booster $booster,
        /** Price in whole coins, frozen at purchase time. */
        #[ORM\Column]
        private int $price,
        #[ORM\Column]
        private \DateTimeImmutable $requestedAt,
    ) {
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getBooster(): Booster
    {
        return $this->booster;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getStatus(): BoosterPurchaseStatusEnum
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return BoosterPurchaseStatusEnum::PENDING === $this->status;
    }

    public function getCoinTransactionId(): ?string
    {
        return $this->coinTransactionId;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getExternalIdentifier(): string
    {
        return self::EXTERNAL_IDENTIFIER_PREFIX . $this->id;
    }

    public function complete(string $coinTransactionId, \DateTimeImmutable $at): void
    {
        $this->status = BoosterPurchaseStatusEnum::COMPLETED;
        $this->coinTransactionId = $coinTransactionId;
        $this->resolvedAt = $at;
    }

    public function fail(string $reason, \DateTimeImmutable $at): void
    {
        $this->status = BoosterPurchaseStatusEnum::FAILED;
        $this->failureReason = $reason;
        $this->resolvedAt = $at;
    }
}
