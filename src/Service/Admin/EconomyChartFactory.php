<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\EconomyDashboard;
use App\Dto\Admin\RarityComparisonRow;
use App\Dto\Admin\WeeklyRecycleStats;
use App\Enum\Admin\BoosterChannelEnum;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Turns the (cached) dashboard DTO into ux-chartjs charts. Colors are left to
 * assets/admin_dashboard.js: datasets only carry a seriesSlot (categorical
 * order) or seriesRole, resolved against the admin light/dark theme.
 */
final readonly class EconomyChartFactory
{
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
                ['label' => 'Joueurs actifs', 'data' => array_values($dashboard->activePlayersPerDay), 'seriesSlot' => 0, 'tension' => 0.25, 'pointRadius' => 0, 'pointHoverRadius' => 5, 'fill' => false],
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
        ];
    }

    /**
     * @param list<string>               $labels
     * @param list<array<string, mixed>> $datasets
     */
    private function chart(string $type, array $labels, array $datasets, bool $stacked = false, bool $percent = false): Chart
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

        return $chart;
    }

    private function formatDay(string $day): string
    {
        // Y-m-d → d/m
        return substr($day, 8, 2) . '/' . substr($day, 5, 2);
    }
}
