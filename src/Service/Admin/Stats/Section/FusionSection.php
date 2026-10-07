<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsFormat;

final readonly class FusionSection extends AbstractStatsSection
{
    private const int TOP = 10;

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::FUSION;
    }

    public function build(StatsContext $context): array
    {
        $totals = $this->db->one(<<<'SQL'
            SELECT COUNT(*) AS operations, COALESCE(SUM(fusion_count), 0) AS fusions,
                   COALESCE(SUM(copies_consumed), 0) AS copies, COALESCE(SUM(holos_created), 0) AS holos,
                   COUNT(DISTINCT discord_user_id) AS players,
                   COUNT(*) FILTER (WHERE fused_at >= :since) AS period_operations,
                   COALESCE(SUM(fusion_count) FILTER (WHERE fused_at >= :since), 0) AS period_fusions,
                   COALESCE(SUM(copies_consumed) FILTER (WHERE fused_at >= :since), 0) AS period_copies,
                   COALESCE(SUM(holos_created) FILTER (WHERE fused_at >= :since), 0) AS period_holos,
                   COUNT(DISTINCT discord_user_id) FILTER (WHERE fused_at >= :since) AS period_players
            FROM fusion_operation
            SQL, ['since' => $context->since]);

        $perDay = [];
        foreach ($this->db->all(\sprintf('SELECT %s AS day, COUNT(*) AS operations, COALESCE(SUM(fusion_count), 0) AS fusions, COALESCE(SUM(copies_consumed), 0) AS copies, COALESCE(SUM(holos_created), 0) AS holos FROM fusion_operation WHERE fused_at >= :since GROUP BY day', $this->db->day('fused_at')), ['since' => $context->since]) as $row) {
            $perDay[StatsFormat::string($row, 'day')] = $row;
        }

        return [
            'totals' => [
                'allTime' => ['operations' => StatsFormat::int($totals, 'operations'), 'fusions' => StatsFormat::int($totals, 'fusions'), 'copiesConsumed' => StatsFormat::int($totals, 'copies'), 'holosCreated' => StatsFormat::int($totals, 'holos'), 'players' => StatsFormat::int($totals, 'players')],
                'period' => ['operations' => StatsFormat::int($totals, 'period_operations'), 'fusions' => StatsFormat::int($totals, 'period_fusions'), 'copiesConsumed' => StatsFormat::int($totals, 'period_copies'), 'holosCreated' => StatsFormat::int($totals, 'period_holos'), 'players' => StatsFormat::int($totals, 'period_players')],
            ],
            'perDay' => $this->series($context, $perDay, ['operations', 'fusions', 'copies', 'holos']),
            'topCards' => $this->topCards($context),
            'topPlayers' => $this->topPlayers($context),
        ];
    }

    /**
     * @return array{allTime: list<array<string, mixed>>, period: list<array<string, mixed>>}
     */
    private function topCards(StatsContext $context): array
    {
        $result = [];

        foreach (['allTime' => '', 'period' => ' WHERE f.fused_at >= :since'] as $scope => $filter) {
            $rows = $this->db->all(
                \sprintf('SELECT c.id, c.name, c.rarity, SUM(f.fusion_count) AS fusions, SUM(f.copies_consumed) AS copies, SUM(f.holos_created) AS holos FROM fusion_operation f JOIN card c ON c.id = f.card_id%s GROUP BY c.id, c.name, c.rarity ORDER BY fusions DESC, c.name, c.id LIMIT %d', $filter, self::TOP),
                '' === $filter ? [] : ['since' => $context->since],
            );

            $result[$scope] = array_map(static fn (array $row): array => [
                'card' => ['id' => StatsFormat::string($row, 'id'), 'name' => StatsFormat::string($row, 'name'), 'rarity' => StatsFormat::string($row, 'rarity')],
                'fusions' => StatsFormat::int($row, 'fusions'),
                'copiesConsumed' => StatsFormat::int($row, 'copies'),
                'holosCreated' => StatsFormat::int($row, 'holos'),
            ], $rows);
        }

        return $result;
    }

    /**
     * @return array{allTime: list<array<string, mixed>>, period: list<array<string, mixed>>}
     */
    private function topPlayers(StatsContext $context): array
    {
        $result = [];

        foreach (['allTime' => '', 'period' => ' WHERE f.fused_at >= :since'] as $scope => $filter) {
            $rows = $this->db->all(
                \sprintf('SELECT u.discord_id, u.username, COUNT(*) AS operations, SUM(f.fusion_count) AS fusions, SUM(f.copies_consumed) AS copies FROM fusion_operation f JOIN discord_user u ON u.discord_id = f.discord_user_id%s GROUP BY u.discord_id, u.username ORDER BY fusions DESC, u.username, u.discord_id LIMIT %d', $filter, self::TOP),
                '' === $filter ? [] : ['since' => $context->since],
            );

            $result[$scope] = array_map(fn (array $row): array => [
                'player' => $this->player(StatsFormat::string($row, 'discord_id'), StatsFormat::string($row, 'username')),
                'operations' => StatsFormat::int($row, 'operations'),
                'fusions' => StatsFormat::int($row, 'fusions'),
                'copiesConsumed' => StatsFormat::int($row, 'copies'),
            ], $rows);
        }

        return $result;
    }
}
