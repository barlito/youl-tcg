<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Market\MarketListingStatusEnum;
use App\Repository\MarketListingRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: MarketListingRepository::class)]
#[ORM\Index(columns: ['seller_id', 'status'])]
#[ORM\Index(columns: ['status', 'created_at'])]
class MarketListing
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\Column(enumType: MarketListingStatusEnum::class)]
    private MarketListingStatusEnum $status = MarketListingStatusEnum::ACTIVE;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'seller_id', referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $seller,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Card $card,
        #[ORM\Column]
        private bool $holo,
        /** Whole coins. */
        #[ORM\Column]
        private int $price,
    ) {
    }

    public function getSeller(): DiscordUser
    {
        return $this->seller;
    }

    public function getCard(): Card
    {
        return $this->card;
    }

    public function isHolo(): bool
    {
        return $this->holo;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): void
    {
        $this->price = $price;
    }

    public function getStatus(): MarketListingStatusEnum
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return MarketListingStatusEnum::ACTIVE === $this->status;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function reserveForPurchase(): void
    {
        $this->status = MarketListingStatusEnum::RESERVED_FOR_PURCHASE;
    }

    public function reopen(): void
    {
        $this->status = MarketListingStatusEnum::ACTIVE;
    }

    public function close(MarketListingStatusEnum $status, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->closedAt = $at;
    }
}
