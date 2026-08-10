<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Card;

/**
 * One card requested to be part of a trade offer (either side), with the
 * copies split by finish — see TradeOfferLine for the quantity semantics.
 */
final readonly class TradeLineRequest
{
    public function __construct(
        public Card $card,
        public int $normalQuantity,
        public int $holoQuantity = 0,
    ) {
    }

    public function getTotalQuantity(): int
    {
        return $this->normalQuantity + $this->holoQuantity;
    }
}
