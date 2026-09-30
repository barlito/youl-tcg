<?php

declare(strict_types=1);

namespace App\Dto\Admin;

// Coins are whole coins, except bank flows and market fees (minor units, 1 coin = 10^8)
final readonly class CoinEconomy
{
    /**
     * @param array<string, int>     $boosterPurchasesPerDay day => completed purchases
     * @param array<string, int>     $boosterCoinsPerDay     day => coins spent
     * @param list<CoinBreakdownRow> $boosterBreakdown
     * @param array<string, int>     $rewardsPerDay          day => rewards paid
     * @param array<string, int>     $rewardCoinsPerDay      day => coins paid
     * @param array<string, int>     $marketSalesPerDay      day => sales
     * @param array<string, int>     $marketVolumePerDay     day => coins paid by buyers
     * @param array<string, int>     $marketFeesMinorPerDay  day => fees kept by the bank
     * @param list<CoinRarityPrice>  $marketRarityPrices
     * @param array<string, int>     $bankInMinorPerDay      day => coins received by the bank
     * @param array<string, int>     $bankOutMinorPerDay     day => coins sent by the bank
     */
    public function __construct(
        public array $boosterPurchasesPerDay,
        public array $boosterCoinsPerDay,
        public array $boosterBreakdown,
        public array $rewardsPerDay,
        public array $rewardCoinsPerDay,
        public array $marketSalesPerDay,
        public array $marketVolumePerDay,
        public array $marketFeesMinorPerDay,
        public array $marketRarityPrices,
        public int $activeListings,
        public array $bankInMinorPerDay,
        public array $bankOutMinorPerDay,
        public CoinAlerts $alerts,
    ) {
    }

    public function bankInMinor(): int
    {
        return array_sum($this->bankInMinorPerDay);
    }

    public function bankOutMinor(): int
    {
        return array_sum($this->bankOutMinorPerDay);
    }

    public function bankNetMinor(): int
    {
        return $this->bankInMinor() - $this->bankOutMinor();
    }

    public function marketFeesMinor(): int
    {
        return array_sum($this->marketFeesMinorPerDay);
    }
}
