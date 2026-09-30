<?php

declare(strict_types=1);

namespace App\Service\Trade;

use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Repository\MarketListingRepository;
use App\Repository\TradeOfferRepository;

final readonly class EngagedCopies
{
    public function __construct(
        private TradeOfferRepository $tradeOfferRepository,
        private MarketListingRepository $marketListingRepository,
    ) {
    }

    /** @return array<string, array{normal: int, holo: int}> */
    public function reservedQuantities(DiscordUser $owner, ?TradeOffer $excludedOffer = null): array
    {
        $reserved = $this->tradeOfferRepository->sumReservedQuantities($owner, $excludedOffer);

        foreach ($this->marketListingRepository->sumReservedQuantities($owner) as $cardId => $listed) {
            $reserved[$cardId] = [
                'normal' => ($reserved[$cardId]['normal'] ?? 0) + $listed['normal'],
                'holo' => ($reserved[$cardId]['holo'] ?? 0) + $listed['holo'],
            ];
        }

        return $reserved;
    }

    /** @return array<string, true> */
    public function engagedCardIds(DiscordUser $owner): array
    {
        return $this->tradeOfferRepository->findEngagedCardIds($owner) + array_fill_keys(array_keys($this->marketListingRepository->sumReservedQuantities($owner)), true);
    }
}
