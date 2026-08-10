<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Trade\TradeOfferSideEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Repository\TradeOfferRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Asynchronous P2P trade offer: the proposer offers copies from their own
 * inventory against copies of a targeted receiver's inventory. While PENDING,
 * the offered copies are reserved (see TradeOfferService) so the same copy can
 * never be engaged in two offers at once; the requested side is only checked,
 * never reserved (the receiver consented to nothing yet).
 */
#[ORM\Entity(repositoryClass: TradeOfferRepository::class)]
#[ORM\Index(name: 'idx_trade_offer_proposer_status', columns: ['proposer_id', 'status'])]
#[ORM\Index(name: 'idx_trade_offer_receiver_status', columns: ['receiver_id', 'status'])]
class TradeOffer
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'proposer_id', referencedColumnName: 'discord_id', nullable: false)]
    private DiscordUser $proposer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'receiver_id', referencedColumnName: 'discord_id', nullable: false)]
    private DiscordUser $receiver;

    #[ORM\Column(options: ['default' => TradeOfferStatusEnum::PENDING])]
    private TradeOfferStatusEnum $status = TradeOfferStatusEnum::PENDING;

    /** When the offer left the PENDING state, whatever the outcome. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /**
     * @var Collection<int, TradeOfferLine>
     */
    #[ORM\OneToMany(targetEntity: TradeOfferLine::class, mappedBy: 'tradeOffer', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function getProposer(): DiscordUser
    {
        return $this->proposer;
    }

    public function setProposer(DiscordUser $proposer): static
    {
        $this->proposer = $proposer;

        return $this;
    }

    public function getReceiver(): DiscordUser
    {
        return $this->receiver;
    }

    public function setReceiver(DiscordUser $receiver): static
    {
        $this->receiver = $receiver;

        return $this;
    }

    public function getStatus(): TradeOfferStatusEnum
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return TradeOfferStatusEnum::PENDING === $this->status;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolve(TradeOfferStatusEnum $status, \DateTimeImmutable $resolvedAt): static
    {
        \assert($status->isFinal());
        $this->status = $status;
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    /**
     * @return Collection<int, TradeOfferLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(TradeOfferLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setTradeOffer($this);
        }

        return $this;
    }

    /**
     * @return list<TradeOfferLine>
     */
    public function getOfferedLines(): array
    {
        return $this->getLinesOfSide(TradeOfferSideEnum::OFFERED);
    }

    /**
     * @return list<TradeOfferLine>
     */
    public function getRequestedLines(): array
    {
        return $this->getLinesOfSide(TradeOfferSideEnum::REQUESTED);
    }

    /**
     * The player a line's copies are debited from: the proposer gives the
     * offered side, the receiver gives the requested side.
     */
    public function getGiverOf(TradeOfferLine $line): DiscordUser
    {
        return TradeOfferSideEnum::OFFERED === $line->getSide() ? $this->proposer : $this->receiver;
    }

    public function getTakerOf(TradeOfferLine $line): DiscordUser
    {
        return TradeOfferSideEnum::OFFERED === $line->getSide() ? $this->receiver : $this->proposer;
    }

    public function involves(DiscordUser $user): bool
    {
        if ($this->proposer->getDiscordId() === $user->getDiscordId()) {
            return true;
        }

        return $this->receiver->getDiscordId() === $user->getDiscordId();
    }

    /**
     * @return list<TradeOfferLine>
     */
    private function getLinesOfSide(TradeOfferSideEnum $side): array
    {
        return array_values($this->lines->filter(
            static fn (TradeOfferLine $line): bool => $line->getSide() === $side,
        )->toArray());
    }
}
