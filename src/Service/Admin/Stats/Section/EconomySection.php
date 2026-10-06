<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Dto\Admin\CoinBreakdownRow;
use App\Dto\Admin\CoinEconomy;
use App\Dto\Admin\CoinRarityPrice;
use App\Enum\Admin\BoosterChannelEnum;
use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\EconomyStatsProvider;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;

final readonly class EconomySection extends AbstractStatsSection
{
    public function __construct(StatsDb $db, private EconomyStatsProvider $economy)
    {
        parent::__construct($db);
    }

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::ECONOMY;
    }

    public function build(StatsContext $context): array
    {
        $coin = $this->economy->buildCoinEconomy($context->days, $context->since);
        $channels = $this->economy->countBoostersPerChannel($context->days, $context->since);

        return [
            'note' => 'ytcg view of the bank only: the Youl Coin API is never called',
            'boosterPurchases' => [
                'perDay' => $this->perDay($context, $coin->boosterPurchasesPerDay, $coin->boosterCoinsPerDay),
                'perBooster' => array_map(static fn (CoinBreakdownRow $row): array => ['label' => $row->label, 'count' => $row->count, 'coins' => StatsFormat::coins($row->coins)], $coin->boosterBreakdown),
            ],
            'universeRewards' => ['perDay' => $this->perDay($context, $coin->rewardsPerDay, $coin->rewardCoinsPerDay)],
            'marketSales' => [
                'perDay' => $this->marketPerDay($context, $coin),
                'volumeTotal' => StatsFormat::coins(array_sum($coin->marketVolumePerDay)),
                'feesTotal' => StatsFormat::minor($coin->marketFeesMinor()),
                'averagePricePerRarity' => array_map(static fn (CoinRarityPrice $row): array => ['rarity' => $row->key, 'sales' => $row->sales, 'averagePriceCoins' => $row->averagePrice], $coin->marketRarityPrices),
                'activeListings' => $coin->activeListings,
            ],
            'bank' => [
                'perDay' => array_map(static fn (string $day): array => [
                    'day' => $day,
                    'in' => StatsFormat::minor($coin->bankInMinorPerDay[$day] ?? 0),
                    'out' => StatsFormat::minor($coin->bankOutMinorPerDay[$day] ?? 0),
                ], $context->days),
                'in' => StatsFormat::minor($coin->bankInMinor()),
                'out' => StatsFormat::minor($coin->bankOutMinor()),
                'net' => StatsFormat::minor($coin->bankNetMinor()),
            ],
            'boostersPerChannel' => $this->channels($context, $channels),
            'alerts' => [
                'pendingBoosterPurchases' => $coin->alerts->pendingBoosterPurchases,
                'failedBoosterPurchases' => $coin->alerts->failedBoosterPurchases,
                'pendingRewards' => $coin->alerts->pendingRewards,
                'failedRewards' => $coin->alerts->failedRewards,
                'paymentPendingSales' => $coin->alerts->paymentPendingSales,
                'payoutPendingSales' => $coin->alerts->payoutPendingSales,
                'refundPendingSales' => $coin->alerts->refundPendingSales,
                'total' => $coin->alerts->total(),
            ],
        ];
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, int> $coins
     *
     * @return list<array<string, mixed>>
     */
    private function perDay(StatsContext $context, array $counts, array $coins): array
    {
        return array_map(static fn (string $day): array => ['day' => $day, 'count' => $counts[$day] ?? 0, 'coins' => StatsFormat::coins($coins[$day] ?? 0)], $context->days);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketPerDay(StatsContext $context, CoinEconomy $coin): array
    {
        return array_map(static fn (string $day): array => [
            'day' => $day,
            'count' => $coin->marketSalesPerDay[$day] ?? 0,
            'volume' => StatsFormat::coins($coin->marketVolumePerDay[$day] ?? 0),
            'fees' => StatsFormat::minor($coin->marketFeesMinorPerDay[$day] ?? 0),
        ], $context->days);
    }

    /**
     * @param array<string, array<string, int>> $channels
     *
     * @return array<string, array<string, mixed>>
     */
    private function channels(StatsContext $context, array $channels): array
    {
        $result = [];

        foreach (BoosterChannelEnum::cases() as $channel) {
            $perDay = $channels[$channel->value] ?? [];
            $result[$channel->value] = ['label' => $channel->label(), 'total' => array_sum($perDay), 'perDay' => $this->dayValues($context, $perDay)];
        }

        return $result;
    }
}
