<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\Stats\PlayerDays;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;
use App\Service\Admin\Stats\StreakCalculator;

final readonly class StreaksSection extends AbstractStatsSection
{
    public function __construct(StatsDb $db, private PlayerDays $playerDays)
    {
        parent::__construct($db);
    }

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::STREAKS;
    }

    public function build(StatsContext $context): array
    {
        $params = ['since' => $context->since];

        $milestones = $this->db->all(<<<'SQL'
            SELECT milestone, COUNT(*) AS awarded, COUNT(*) FILTER (WHERE chosen_booster_id IS NOT NULL) AS chosen,
                   COUNT(*) FILTER (WHERE awarded_at >= :since) AS period_awarded,
                   COUNT(*) FILTER (WHERE chosen_at >= :since) AS period_chosen
            FROM streak_reward
            GROUP BY milestone
            ORDER BY milestone
            SQL, $params);

        $boosters = $this->db->all(<<<'SQL'
            SELECT b.id, COALESCE(b.name, e.name) AS display_name, COUNT(*) AS chosen, COUNT(*) FILTER (WHERE r.chosen_at >= :since) AS period_chosen
            FROM streak_reward r
            JOIN booster b ON b.id = r.chosen_booster_id
            JOIN extension e ON e.id = b.extension_id
            GROUP BY b.id, display_name
            ORDER BY chosen DESC, display_name, b.id
            SQL, $params);

        $totals = $this->db->one(<<<'SQL'
            SELECT COUNT(*) AS awarded, COUNT(*) FILTER (WHERE chosen_booster_id IS NOT NULL) AS chosen,
                   COUNT(*) FILTER (WHERE chosen_booster_id IS NULL) AS pending,
                   COUNT(DISTINCT discord_user_id) AS players
            FROM streak_reward
            SQL);

        return [
            'rewards' => [
                'awarded' => StatsFormat::int($totals, 'awarded'),
                'chosen' => StatsFormat::int($totals, 'chosen'),
                'pending' => StatsFormat::int($totals, 'pending'),
                'players' => StatsFormat::int($totals, 'players'),
                'perMilestone' => array_map(static fn (array $row): array => [
                    'milestone' => StatsFormat::int($row, 'milestone'),
                    'awarded' => StatsFormat::int($row, 'awarded'),
                    'chosen' => StatsFormat::int($row, 'chosen'),
                    'periodAwarded' => StatsFormat::int($row, 'period_awarded'),
                    'periodChosen' => StatsFormat::int($row, 'period_chosen'),
                ], $milestones),
            ],
            'boostersChosen' => array_map(static fn (array $row): array => [
                'booster' => ['id' => StatsFormat::string($row, 'id'), 'displayName' => StatsFormat::string($row, 'display_name')],
                'chosen' => StatsFormat::int($row, 'chosen'),
                'periodChosen' => StatsFormat::int($row, 'period_chosen'),
            ], $boosters),
            'currentStreaks' => $this->currentStreaks($context),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentStreaks(StatsContext $context): array
    {
        $histogram = [];
        $atRisk = 0;
        $best = 0;
        $players = 0;

        foreach ($this->playerDays->openings() as $days) {
            $streak = StreakCalculator::analyse($days, $context->today);
            ++$players;
            $best = max($best, $streak['best']);

            if ($streak['current'] > 0) {
                $histogram[$streak['current']] = ($histogram[$streak['current']] ?? 0) + 1;
                $atRisk += $streak['openedToday'] ? 0 : 1;
            }
        }

        ksort($histogram);

        return [
            'playersWithOpenings' => $players,
            'running' => array_sum($histogram),
            'atRisk' => $atRisk,
            'bestEver' => $best,
            'playersPerLength' => array_map(static fn (int $length, int $count): array => ['length' => $length, 'players' => $count], array_keys($histogram), $histogram),
        ];
    }
}
