<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Coin\UniverseRewardStatusEnum;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsFormat;
use App\Service\Admin\Stats\StatsMath;

final readonly class UniverseRewardsSection extends AbstractStatsSection
{
    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::UNIVERSE_REWARDS;
    }

    public function build(StatsContext $context): array
    {
        $rows = $this->db->all(<<<'SQL'
            SELECT e.slug, e.name, r.status, COUNT(r.id) AS total, COALESCE(SUM(r.amount), 0) AS amount,
                   COUNT(r.id) FILTER (WHERE r.completed_at >= :since) AS period,
                   json_agg(EXTRACT(EPOCH FROM (r.paid_at - r.completed_at))) FILTER (WHERE r.status = :paid AND r.paid_at IS NOT NULL) AS delays
            FROM extension e
            LEFT JOIN universe_completion_reward r ON r.extension_id = e.id
            GROUP BY e.id, e.slug, e.name, r.status
            ORDER BY e.name, e.id
            SQL, ['since' => $context->since, 'paid' => UniverseRewardStatusEnum::PAID->value]);

        $extensions = [];
        $allDelays = [];

        foreach ($rows as $row) {
            $slug = StatsFormat::string($row, 'slug');
            $extensions[$slug] ??= [
                'extension' => $slug,
                'name' => StatsFormat::string($row, 'name'),
                'completions' => 0,
                'periodCompletions' => 0,
                'byStatus' => $this->emptyStatuses(),
                'paidDelays' => [],
            ];
            $status = StatsFormat::nullableString($row, 'status');

            if (null === $status) {
                continue;
            }

            $extensions[$slug]['completions'] += StatsFormat::int($row, 'total');
            $extensions[$slug]['periodCompletions'] += StatsFormat::int($row, 'period');
            $extensions[$slug]['byStatus'][$status] = ['count' => StatsFormat::int($row, 'total'), 'amount' => StatsFormat::coins(StatsFormat::int($row, 'amount'))];
            $delays = array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, StatsFormat::jsonList($row, 'delays'));
            $extensions[$slug]['paidDelays'] = [...$extensions[$slug]['paidDelays'], ...$delays];
            $allDelays = [...$allDelays, ...$delays];
        }

        $totalsByStatus = $this->db->keyed('SELECT status, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount FROM universe_completion_reward GROUP BY status', 'status');
        $byStatus = [];
        foreach (UniverseRewardStatusEnum::cases() as $status) {
            $byStatus[$status->value] = ['count' => StatsFormat::int($totalsByStatus[$status->value] ?? [], 'total'), 'amount' => StatsFormat::coins(StatsFormat::int($totalsByStatus[$status->value] ?? [], 'amount'))];
        }

        $perDay = [];
        foreach ($this->db->all(\sprintf('SELECT %s AS day, COUNT(*) AS completions FROM universe_completion_reward WHERE completed_at >= :since GROUP BY day', $this->db->day('completed_at')), ['since' => $context->since]) as $row) {
            $perDay[StatsFormat::string($row, 'day')] = $row;
        }

        return [
            'byStatus' => $byStatus,
            'paidDelaySeconds' => $this->delaySummary($allDelays),
            'completionsPerDay' => $this->series($context, $perDay, ['completions']),
            'perExtension' => array_values(array_map(fn (array $entry): array => [
                ...array_diff_key($entry, ['paidDelays' => 0]),
                'paidDelaySeconds' => $this->delaySummary($entry['paidDelays']),
            ], $extensions)),
        ];
    }

    /**
     * @return array<string, array{count: int, amount: array{minor: string, coins: int}}>
     */
    private function emptyStatuses(): array
    {
        $statuses = [];

        foreach (UniverseRewardStatusEnum::cases() as $status) {
            $statuses[$status->value] = ['count' => 0, 'amount' => StatsFormat::coins(0)];
        }

        return $statuses;
    }

    /**
     * @param list<float> $delays
     *
     * @return array{count: int, average: float|null, median: float|null, max: float|null}
     */
    private function delaySummary(array $delays): array
    {
        return ['count' => \count($delays), 'average' => StatsMath::average($delays), 'median' => StatsMath::median($delays), 'max' => [] === $delays ? null : max($delays)];
    }
}
