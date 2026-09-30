<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\CoinEconomy;
use App\Dto\Admin\EconomyDashboard;
use App\Dto\Admin\RarityComparisonRow;
use App\Dto\Admin\WeeklyRecycleStats;
use App\Enum\Admin\BoosterChannelEnum;
use App\Service\Coin\CoinAmount;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Turns the (cached) dashboard DTO into ux-chartjs charts. Colors are left to
 * assets/admin_dashboard.js: datasets only carry a seriesSlot (categorical
 * order) or seriesRole, resolved against the admin light/dark theme.
 */
final readonly class EconomyChartFactory
{
    private const array LINE = ['tension' => 0.25, 'pointRadius' => 0, 'pointHoverRadius' => 5, 'fill' => false];

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * @return array<string, Chart>
     */
    public function build(EconomyDashboard $dashboard): array
    {
        $dayLabels = array_map($this->formatDay(...), $dashboard->days);

        return [
            'openings' => $this->chart(Chart::TYPE_BAR, $dayLabels, [
                ['label' => 'Packs ouverts', 'data' => array_values($dashboard->openingsPerDay), 'seriesSlot' => 0],
            ]),
            'activePlayers' => $this->chart(Chart::TYPE_LINE, $dayLabels, [
                ['label' => 'Joueurs actifs', 'data' => array_values($dashboard->activePlayersPerDay), 'seriesSlot' => 0, ...self::LINE],
            ]),
            'channels' => $this->chart(Chart::TYPE_BAR, $dayLabels, array_map(
                static fn (BoosterChannelEnum $channel, int $slot): array => [
                    'label' => $channel->label(),
                    'data' => array_values($dashboard->boostersPerChannel[$channel->value] ?? []),
                    'seriesSlot' => $slot,
                ],
                BoosterChannelEnum::cases(),
                array_keys(BoosterChannelEnum::cases()),
            ), stacked: true),
            'trades' => $this->chart(Chart::TYPE_LINE, $dayLabels, [
                ['label' => 'Offres créées', 'data' => array_values($dashboard->trades->createdPerDay), 'seriesSlot' => 0, ...self::LINE],
                ['label' => 'Acceptées', 'data' => array_values($dashboard->trades->acceptedPerDay), 'seriesSlot' => 2, ...self::LINE],
                ['label' => 'Refusées', 'data' => array_values($dashboard->trades->refusedPerDay), 'seriesSlot' => 1, ...self::LINE],
            ]),
            'recycles' => $this->chart(
                Chart::TYPE_BAR,
                array_map(fn (WeeklyRecycleStats $week): string => 'sem. du ' . $this->formatDay($week->weekStart), $dashboard->weeklyRecycles),
                [
                    ['label' => 'Opérations', 'data' => array_map(static fn (WeeklyRecycleStats $week): int => $week->operations, $dashboard->weeklyRecycles), 'seriesSlot' => 0],
                    ['label' => 'Boosters obtenus', 'data' => array_map(static fn (WeeklyRecycleStats $week): int => $week->boosters, $dashboard->weeklyRecycles), 'seriesSlot' => 1],
                ],
            ),
            'rarities' => $this->chart(
                Chart::TYPE_BAR,
                array_map(static fn (RarityComparisonRow $row): string => $row->label, $dashboard->rarityComparison->rows),
                [
                    ['label' => 'Attendu (%)', 'data' => array_map(static fn (RarityComparisonRow $row): float => $row->expectedShare, $dashboard->rarityComparison->rows), 'seriesRole' => 'expected'],
                    ['label' => 'Observé (%)', 'data' => array_map(static fn (RarityComparisonRow $row): float => $row->observedShare, $dashboard->rarityComparison->rows), 'seriesSlot' => 0],
                ],
                percent: true,
            ),
            ...$this->buildCoinCharts($dashboard->coin, $dayLabels),
        ];
    }

    /**
     * @param list<string> $dayLabels
     *
     * @return array<string, Chart>
     */
    private function buildCoinCharts(CoinEconomy $coin, array $dayLabels): array
    {
        $coins = static fn (array $minor): array => array_map(static fn (int $amount): float => $amount / 10 ** CoinAmount::SCALE, array_values($minor));

        return [
            'coinBoosters' => $this->chart(Chart::TYPE_BAR, $dayLabels, [
                ['label' => 'Achats', 'data' => array_values($coin->boosterPurchasesPerDay), 'seriesSlot' => 0],
                ['label' => 'Coins dépensés', 'data' => array_values($coin->boosterCoinsPerDay), 'seriesSlot' => 1, 'type' => 'line', 'yAxisID' => 'y2', ...self::LINE],
            ], secondaryAxis: true),
            'coinRewards' => $this->chart(Chart::TYPE_BAR, $dayLabels, [
                ['label' => 'Récompenses', 'data' => array_values($coin->rewardsPerDay), 'seriesSlot' => 0],
                ['label' => 'Coins versés', 'data' => array_values($coin->rewardCoinsPerDay), 'seriesSlot' => 1, 'type' => 'line', 'yAxisID' => 'y2', ...self::LINE],
            ], secondaryAxis: true),
            'coinMarket' => $this->chart(Chart::TYPE_BAR, $dayLabels, [
                ['label' => 'Ventes', 'data' => array_values($coin->marketSalesPerDay), 'seriesSlot' => 0],
                ['label' => 'Volume (coins)', 'data' => array_values($coin->marketVolumePerDay), 'seriesSlot' => 1, 'type' => 'line', 'yAxisID' => 'y2', ...self::LINE],
                ['label' => 'Commissions (coins)', 'data' => $coins($coin->marketFeesMinorPerDay), 'seriesSlot' => 2, 'type' => 'line', 'yAxisID' => 'y2', ...self::LINE],
            ], secondaryAxis: true),
            'coinBank' => $this->chart(Chart::TYPE_LINE, $dayLabels, [
                ['label' => 'Entrées (coins)', 'data' => $coins($coin->bankInMinorPerDay), 'seriesSlot' => 2, ...self::LINE],
                ['label' => 'Sorties (coins)', 'data' => $coins($coin->bankOutMinorPerDay), 'seriesSlot' => 1, ...self::LINE],
            ]),
        ];
    }

    /**
     * @param list<string>               $labels
     * @param list<array<string, mixed>> $datasets
     */
    private function chart(string $type, array $labels, array $datasets, bool $stacked = false, bool $percent = false, bool $secondaryAxis = false): Chart
    {
        $chart = $this->chartBuilder->createChart($type);
        $chart->setData(['labels' => $labels, 'datasets' => $datasets]);
        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['display' => \count($datasets) > 1, 'position' => 'bottom']],
            'scales' => [
                'x' => ['stacked' => $stacked, 'grid' => ['display' => false]],
                'y' => ['stacked' => $stacked, 'beginAtZero' => true, 'ticks' => $percent ? ['format' => ['maximumFractionDigits' => 2]] : ['precision' => 0]],
            ],
        ]);

        if ($secondaryAxis) {
            $options = $chart->getOptions();
            $options['scales']['y2'] = ['position' => 'right', 'beginAtZero' => true, 'grid' => ['drawOnChartArea' => false]];
            $chart->setOptions($options);
        }

        return $chart;
    }

    private function formatDay(string $day): string
    {
        // Y-m-d → d/m
        return substr($day, 8, 2) . '/' . substr($day, 5, 2);
    }
}
