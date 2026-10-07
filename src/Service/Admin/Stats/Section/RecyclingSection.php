<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Dto\Admin\WeeklyRecycleStats;
use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Service\Admin\EconomyStatsProvider;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;

final readonly class RecyclingSection extends AbstractStatsSection
{
    private const int TOP = 10;

    public function __construct(StatsDb $db, private EconomyStatsProvider $economy)
    {
        parent::__construct($db);
    }

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::RECYCLING;
    }

    public function build(StatsContext $context): array
    {
        $totals = $this->db->one(<<<'SQL'
            SELECT COUNT(*) AS operations, COALESCE(SUM(points), 0) AS points, COALESCE(SUM(booster_count), 0) AS boosters,
                   COUNT(*) FILTER (WHERE recycled_at >= :since) AS period_operations,
                   COALESCE(SUM(points) FILTER (WHERE recycled_at >= :since), 0) AS period_points,
                   COALESCE(SUM(booster_count) FILTER (WHERE recycled_at >= :since), 0) AS period_boosters,
                   COUNT(DISTINCT discord_user_id) AS players,
                   COUNT(DISTINCT discord_user_id) FILTER (WHERE recycled_at >= :since) AS period_players
            FROM recycle_operation
            SQL, ['since' => $context->since]);

        $cards = $this->db->one(<<<'SQL'
            SELECT COALESCE(SUM(oc.quantity), 0) AS copies, COALESCE(SUM(oc.holo_quantity), 0) AS holos,
                   COALESCE(SUM(oc.quantity) FILTER (WHERE o.recycled_at >= :since), 0) AS period_copies,
                   COALESCE(SUM(oc.holo_quantity) FILTER (WHERE o.recycled_at >= :since), 0) AS period_holos
            FROM recycle_operation_card oc
            JOIN recycle_operation o ON o.id = oc.recycle_operation_id
            SQL, ['since' => $context->since]);

        $perDay = [];
        foreach ($this->db->all(\sprintf('SELECT %s AS day, COUNT(*) AS operations, COALESCE(SUM(points), 0) AS points, COALESCE(SUM(booster_count), 0) AS boosters FROM recycle_operation WHERE recycled_at >= :since GROUP BY day', $this->db->day('recycled_at')), ['since' => $context->since]) as $row) {
            $perDay[StatsFormat::string($row, 'day')] = $row;
        }

        return [
            'totals' => [
                'allTime' => ['operations' => StatsFormat::int($totals, 'operations'), 'points' => StatsFormat::int($totals, 'points'), 'boosters' => StatsFormat::int($totals, 'boosters'), 'copies' => StatsFormat::int($cards, 'copies'), 'holoCopies' => StatsFormat::int($cards, 'holos'), 'players' => StatsFormat::int($totals, 'players')],
                'period' => ['operations' => StatsFormat::int($totals, 'period_operations'), 'points' => StatsFormat::int($totals, 'period_points'), 'boosters' => StatsFormat::int($totals, 'period_boosters'), 'copies' => StatsFormat::int($cards, 'period_copies'), 'holoCopies' => StatsFormat::int($cards, 'period_holos'), 'players' => StatsFormat::int($totals, 'period_players')],
            ],
            'perWeek' => array_map(static fn (WeeklyRecycleStats $week): array => [
                'weekStart' => $week->weekStart, 'operations' => $week->operations, 'points' => $week->points, 'boosters' => $week->boosters,
            ], $this->economy->aggregateWeeklyRecycles($context->startDay, $context->since)),
            'perDay' => $this->series($context, $perDay, ['operations', 'points', 'boosters']),
            'boostersProduced' => $this->boostersProduced($context),
            'copiesByRarity' => $this->copiesByRarity($context),
            'topCards' => $this->topCards($context),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boostersProduced(StatsContext $context): array
    {
        $rows = $this->db->all(<<<'SQL'
            SELECT b.id, COALESCE(b.name, e.name) AS display_name, COUNT(*) AS operations, SUM(o.booster_count) AS boosters, SUM(o.points) AS points,
                   COUNT(*) FILTER (WHERE o.recycled_at >= :since) AS period_operations,
                   COALESCE(SUM(o.booster_count) FILTER (WHERE o.recycled_at >= :since), 0) AS period_boosters
            FROM recycle_operation o
            JOIN booster b ON b.id = o.booster_id
            JOIN extension e ON e.id = b.extension_id
            GROUP BY b.id, display_name
            ORDER BY boosters DESC, display_name, b.id
            SQL, ['since' => $context->since]);

        return array_map(static fn (array $row): array => [
            'booster' => ['id' => StatsFormat::string($row, 'id'), 'displayName' => StatsFormat::string($row, 'display_name')],
            'operations' => StatsFormat::int($row, 'operations'),
            'boosters' => StatsFormat::int($row, 'boosters'),
            'points' => StatsFormat::int($row, 'points'),
            'periodOperations' => StatsFormat::int($row, 'period_operations'),
            'periodBoosters' => StatsFormat::int($row, 'period_boosters'),
        ], $rows);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function copiesByRarity(StatsContext $context): array
    {
        $rows = $this->db->keyed(<<<'SQL'
            SELECT c.rarity, SUM(oc.quantity - oc.holo_quantity) AS normal, SUM(oc.holo_quantity) AS holo,
                   COALESCE(SUM(oc.quantity - oc.holo_quantity) FILTER (WHERE o.recycled_at >= :since), 0) AS period_normal,
                   COALESCE(SUM(oc.holo_quantity) FILTER (WHERE o.recycled_at >= :since), 0) AS period_holo
            FROM recycle_operation_card oc
            JOIN recycle_operation o ON o.id = oc.recycle_operation_id
            JOIN card c ON c.id = oc.card_id
            GROUP BY c.rarity
            SQL, 'rarity', ['since' => $context->since]);

        $result = [];

        foreach (CardRarityEnum::ascending() as $rarity) {
            $row = $rows[$rarity->value] ?? [];
            $normal = StatsFormat::int($row, 'normal');
            $holo = StatsFormat::int($row, 'holo');
            $result[$rarity->value] = [
                'normal' => $normal,
                'holo' => $holo,
                'points' => $normal * $rarity->recyclePoints() + $holo * $rarity->holoRecyclePoints(),
                'periodNormal' => StatsFormat::int($row, 'period_normal'),
                'periodHolo' => StatsFormat::int($row, 'period_holo'),
            ];
        }

        return $result;
    }

    /**
     * @return array{allTime: list<array<string, mixed>>, period: list<array<string, mixed>>}
     */
    private function topCards(StatsContext $context): array
    {
        $result = [];

        foreach (['allTime' => '', 'period' => ' WHERE o.recycled_at >= :since'] as $scope => $filter) {
            $rows = $this->db->all(
                \sprintf('SELECT c.id, c.name, c.rarity, SUM(oc.quantity) AS copies, SUM(oc.holo_quantity) AS holos FROM recycle_operation_card oc JOIN recycle_operation o ON o.id = oc.recycle_operation_id JOIN card c ON c.id = oc.card_id%s GROUP BY c.id, c.name, c.rarity ORDER BY copies DESC, c.name, c.id LIMIT %d', $filter, self::TOP),
                '' === $filter ? [] : ['since' => $context->since],
            );

            $result[$scope] = array_map(static fn (array $row): array => [
                'card' => ['id' => StatsFormat::string($row, 'id'), 'name' => StatsFormat::string($row, 'name'), 'rarity' => StatsFormat::string($row, 'rarity')],
                'copies' => StatsFormat::int($row, 'copies'),
                'holoCopies' => StatsFormat::int($row, 'holos'),
            ], $rows);
        }

        return $result;
    }
}
