<?php

declare(strict_types=1);

namespace App\Service\Trade;

use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Repository\MarketListingRepository;
use App\Repository\TradeOfferRepository;

/**
 * Single reservation ledger of a player's copies: what their PENDING trade offers
 * and their live market listings hold. Never gated by a feature flag: a frozen
 * offer or a listing keeps its copies reserved whatever is switched on.
 */
final readonly class EngagedCopies
{
    public function __construct(
        private TradeOfferRepository $tradeOfferRepository,
        private MarketListingRepository $marketListingRepository,
    ) {
    }

    /**
     * @return array<string, array{normal: int, holo: int}> card id => reserved copies per finish
     */
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

    /**
     * Cards the player engages in a pending offer as proposer or in a listing: not recyclable at all.
     *
     * @return array<string, true> card id => engaged
     */
    public function engagedCardIds(DiscordUser $owner): array
    {
        return $this->tradeOfferRepository->findEngagedCardIds($owner) + $this->listedCardIds($owner);
    }

    /**
     * @return array<string, true> card id => at least one copy in an engaged listing
     */
    public function listedCardIds(DiscordUser $owner): array
    {
        return array_fill_keys(array_map(strval(...), array_keys($this->marketListingRepository->sumReservedQuantities($owner))), true);
    }
}
