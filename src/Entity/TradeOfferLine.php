<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Trade\TradeOfferSideEnum;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * One card of a trade offer, on one side. Quantities are stored SEPARATELY per
 * finish (normal vs holo) — unlike UserCard, whose `quantity` is the total and
 * `holoQuantity` a sub-count. Debiting a line therefore removes
 * (normalQuantity + holoQuantity) from UserCard.quantity and holoQuantity from
 * UserCard.holoQuantity.
 */
#[ORM\Entity]
class TradeOfferLine
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private TradeOffer $tradeOffer;

    #[ORM\Column]
    private TradeOfferSideEnum $side;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Card $card;

    /** Non-holo copies traded by this line. */
    #[ORM\Column(options: ['default' => 0])]
    private int $normalQuantity = 0;

    /** Holo copies traded by this line. */
    #[ORM\Column(options: ['default' => 0])]
    private int $holoQuantity = 0;

    public function getTradeOffer(): TradeOffer
    {
        return $this->tradeOffer;
    }

    public function setTradeOffer(TradeOffer $tradeOffer): static
    {
        $this->tradeOffer = $tradeOffer;

        return $this;
    }

    public function getSide(): TradeOfferSideEnum
    {
        return $this->side;
    }

    public function setSide(TradeOfferSideEnum $side): static
    {
        $this->side = $side;

        return $this;
    }

    public function getCard(): Card
    {
        return $this->card;
    }

    public function setCard(Card $card): static
    {
        $this->card = $card;

        return $this;
    }

    public function getNormalQuantity(): int
    {
        return $this->normalQuantity;
    }

    public function setNormalQuantity(int $normalQuantity): static
    {
        $this->normalQuantity = $normalQuantity;

        return $this;
    }

    public function getHoloQuantity(): int
    {
        return $this->holoQuantity;
    }

    public function setHoloQuantity(int $holoQuantity): static
    {
        $this->holoQuantity = $holoQuantity;

        return $this;
    }

    /** All copies moved by this line, whatever the finish. */
    public function getTotalQuantity(): int
    {
        return $this->normalQuantity + $this->holoQuantity;
    }
}
