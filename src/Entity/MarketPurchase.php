<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Repository\MarketPurchaseRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: MarketPurchaseRepository::class)]
#[ORM\Index(columns: ['buyer_id', 'status'])]
#[ORM\Index(columns: ['seller_id', 'status'])]
#[ORM\Index(columns: ['status', 'requested_at'])]
class MarketPurchase
{
    use IdUuidTrait;
    use TimestampableEntity;

    private const string PAYMENT_PREFIX = 'ytcg:market-payment:';
    private const string PAYOUT_PREFIX = 'ytcg:market-payout:';
    private const string REFUND_PREFIX = 'ytcg:market-refund:';

    #[ORM\Column(enumType: MarketPurchaseStatusEnum::class)]
    private MarketPurchaseStatusEnum $status = MarketPurchaseStatusEnum::PAYMENT_PENDING;

    #[ORM\Column(nullable: true)]
    private ?string $paymentTransactionId = null;

    #[ORM\Column(nullable: true)]
    private ?string $payoutTransactionId = null;

    #[ORM\Column(nullable: true)]
    private ?string $refundTransactionId = null;

    #[ORM\Column(nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private MarketListing $listing,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'buyer_id', referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $buyer,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'seller_id', referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $seller,
        #[ORM\Column]
        private int $price,
        #[ORM\Column(type: Types::BIGINT)]
        private int | string $feeMinor,
        #[ORM\Column]
        private \DateTimeImmutable $requestedAt,
    ) {
    }

    public function getListing(): MarketListing
    {
        return $this->listing;
    }

    public function getBuyer(): DiscordUser
    {
        return $this->buyer;
    }

    public function getSeller(): DiscordUser
    {
        return $this->seller;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getFeeMinor(): string
    {
        return (string) $this->feeMinor;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getStatus(): MarketPurchaseStatusEnum
    {
        return $this->status;
    }

    public function getPaymentTransactionId(): ?string
    {
        return $this->paymentTransactionId;
    }

    public function getPayoutTransactionId(): ?string
    {
        return $this->payoutTransactionId;
    }

    public function getRefundTransactionId(): ?string
    {
        return $this->refundTransactionId;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getPaymentIdentifier(): string
    {
        return self::PAYMENT_PREFIX . $this->id;
    }

    public function getPayoutIdentifier(): string
    {
        return self::PAYOUT_PREFIX . $this->id;
    }

    public function getRefundIdentifier(): string
    {
        return self::REFUND_PREFIX . $this->id;
    }

    public function markCardTransferred(string $paymentTransactionId): void
    {
        $this->status = MarketPurchaseStatusEnum::CARD_TRANSFERRED;
        $this->paymentTransactionId = $paymentTransactionId;
    }

    public function markRefundPending(string $paymentTransactionId, string $reason): void
    {
        $this->status = MarketPurchaseStatusEnum::REFUND_PENDING;
        $this->paymentTransactionId = $paymentTransactionId;
        $this->failureReason = $reason;
    }

    public function complete(?string $payoutTransactionId, \DateTimeImmutable $at): void
    {
        $this->status = MarketPurchaseStatusEnum::COMPLETED;
        $this->payoutTransactionId = $payoutTransactionId;
        $this->resolvedAt = $at;
    }

    public function refund(string $refundTransactionId, \DateTimeImmutable $at): void
    {
        $this->status = MarketPurchaseStatusEnum::REFUNDED;
        $this->refundTransactionId = $refundTransactionId;
        $this->resolvedAt = $at;
    }

    public function fail(string $reason, \DateTimeImmutable $at): void
    {
        $this->status = MarketPurchaseStatusEnum::FAILED;
        $this->failureReason = $reason;
        $this->resolvedAt = $at;
    }
}
