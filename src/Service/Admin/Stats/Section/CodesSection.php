<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsFormat;

final readonly class CodesSection extends AbstractStatsSection
{
    private const array COUNTERS = ['codes', 'exhausted', 'expired', 'disabled', 'assigned', 'unlimited', 'active', 'uses', 'limited_capacity', 'limited_uses', 'redemptions', 'boosters_granted'];

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::CODES;
    }

    public function build(StatsContext $context): array
    {
        $rows = $this->db->all(<<<'SQL'
            SELECT bc.batch_label,
                   COUNT(*) AS codes,
                   COUNT(*) FILTER (WHERE bc.max_uses IS NOT NULL AND bc.uses >= bc.max_uses) AS exhausted,
                   COUNT(*) FILTER (WHERE bc.expires_at IS NOT NULL AND bc.expires_at <= :now) AS expired,
                   COUNT(*) FILTER (WHERE bc.disabled) AS disabled,
                   COUNT(*) FILTER (WHERE bc.assigned_to_id IS NOT NULL) AS assigned,
                   COUNT(*) FILTER (WHERE bc.max_uses IS NULL) AS unlimited,
                   COUNT(*) FILTER (WHERE NOT bc.disabled AND (bc.max_uses IS NULL OR bc.uses < bc.max_uses) AND (bc.expires_at IS NULL OR bc.expires_at > :now)) AS active,
                   COALESCE(SUM(bc.uses), 0) AS uses,
                   COALESCE(SUM(bc.max_uses), 0) AS limited_capacity,
                   COALESCE(SUM(bc.uses) FILTER (WHERE bc.max_uses IS NOT NULL), 0) AS limited_uses,
                   COALESCE(SUM(r.redemptions), 0) AS redemptions,
                   COALESCE(SUM(r.boosters), 0) AS boosters_granted,
                   MIN(bc.created_at) AS first_created_at
            FROM booster_code bc
            LEFT JOIN (
                SELECT booster_code_id, COUNT(*) AS redemptions, SUM(quantity) AS boosters FROM booster_code_redemption GROUP BY booster_code_id
            ) r ON r.booster_code_id = bc.id
            GROUP BY bc.batch_label
            ORDER BY MIN(bc.created_at), bc.batch_label
            SQL, ['now' => $context->now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);

        $totals = array_fill_keys(self::COUNTERS, 0);
        $batches = [];

        foreach ($rows as $row) {
            foreach (self::COUNTERS as $counter) {
                $totals[$counter] += StatsFormat::int($row, $counter);
            }

            $batches[] = ['batchLabel' => StatsFormat::nullableString($row, 'batch_label'), 'firstCreatedAt' => StatsFormat::iso($row, 'first_created_at'), ...$this->counters($row)];
        }

        $perDay = [];
        foreach ($this->db->all(\sprintf('SELECT %s AS day, COUNT(*) AS redemptions, COALESCE(SUM(quantity), 0) AS boosters, COUNT(DISTINCT discord_user_id) AS players FROM booster_code_redemption WHERE redeemed_at >= :since GROUP BY day', $this->db->day('redeemed_at')), ['since' => $context->since]) as $row) {
            $perDay[StatsFormat::string($row, 'day')] = $row;
        }

        return [
            'totals' => $this->counters($totals),
            'batches' => $batches,
            'usesPerDay' => $this->series($context, $perDay, ['redemptions', 'boosters', 'players']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function counters(array $row): array
    {
        return [
            'codes' => StatsFormat::int($row, 'codes'),
            'active' => StatsFormat::int($row, 'active'),
            'exhausted' => StatsFormat::int($row, 'exhausted'),
            'expired' => StatsFormat::int($row, 'expired'),
            'disabled' => StatsFormat::int($row, 'disabled'),
            'assigned' => StatsFormat::int($row, 'assigned'),
            'unlimited' => StatsFormat::int($row, 'unlimited'),
            'uses' => StatsFormat::int($row, 'uses'),
            'maxUsesTotal' => StatsFormat::int($row, 'limited_capacity'),
            'usageRatePercent' => StatsFormat::share(StatsFormat::int($row, 'limited_uses'), StatsFormat::int($row, 'limited_capacity')),
            'boostersGranted' => StatsFormat::int($row, 'boosters_granted'),
        ];
    }
}
