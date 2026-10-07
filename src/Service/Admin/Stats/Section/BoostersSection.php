<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Entity\Booster;
use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Booster\BoosterPurchaseStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsFormat;

final readonly class BoostersSection extends AbstractStatsSection
{
    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::BOOSTERS;
    }

    public function build(StatsContext $context): array
    {
        $boosters = $this->db->all(<<<'SQL'
            SELECT b.id, b.name, COALESCE(b.name, e.name) AS display_name, b.claimable, b.purchasable, b.purchase_price, b.rarity_rates,
                   e.id AS extension_id, e.slug AS extension_slug, e.name AS extension_name, e.status AS extension_status
            FROM booster b
            JOIN extension e ON e.id = b.extension_id
            ORDER BY e.name, display_name, b.id
            SQL);

        $metrics = $this->metrics($context);
        $inventory = $this->db->keyed(
            'SELECT booster_id, COALESCE(SUM(quantity), 0) AS copies, COUNT(*) FILTER (WHERE quantity > 0) AS holders FROM user_booster GROUP BY booster_id',
            'booster_id',
        );

        $result = [];

        foreach ($boosters as $row) {
            $id = StatsFormat::string($row, 'id');
            $slots = $this->slots(StatsFormat::json($row, 'rarity_rates'));
            $own = $metrics[$id] ?? [];
            $purchases = [];

            foreach (BoosterPurchaseStatusEnum::cases() as $status) {
                $purchases[$status->value] = $this->counter($own, 'purchase_' . $status->value);
            }

            $price = StatsFormat::nullableFloat($row, 'purchase_price');

            $result[] = [
                'id' => $id,
                'name' => StatsFormat::nullableString($row, 'name'),
                'displayName' => StatsFormat::string($row, 'display_name'),
                'extension' => [
                    'id' => StatsFormat::string($row, 'extension_id'),
                    'slug' => StatsFormat::string($row, 'extension_slug'),
                    'name' => StatsFormat::string($row, 'extension_name'),
                    'status' => strtolower(ExtensionStatusEnum::from(StatsFormat::int($row, 'extension_status'))->name),
                ],
                'claimable' => StatsFormat::bool($row, 'claimable'),
                'purchasable' => StatsFormat::bool($row, 'purchasable'),
                'purchasePrice' => null === $price ? null : StatsFormat::coins((int) $price),
                'cardCount' => \count($slots),
                'rarityRates' => $slots,
                'dropRates' => $this->dropRates($slots),
                'averageHoloChance' => [] === $slots ? null : round(array_sum(array_column($slots, 'holoChance')) / \count($slots), 2),
                'channels' => [
                    'claims' => $this->counter($own, 'claims'),
                    'openings' => $this->counter($own, 'openings'),
                    'purchases' => $purchases,
                    'purchaseCoinsSpent' => $this->coinsCounter($own, 'purchase_completed'),
                    'codeRedemptions' => $this->counter($own, 'code_redemptions'),
                    'codeBoostersGranted' => $this->counter($own, 'code_boosters'),
                    'recycleOperations' => $this->counter($own, 'recycle_operations'),
                    'recycleBoostersGranted' => $this->counter($own, 'recycle_boosters'),
                    'streakRewardsChosen' => $this->counter($own, 'streak_rewards'),
                ],
                'unopenedInInventories' => [
                    'copies' => StatsFormat::int($inventory[$id] ?? [], 'copies'),
                    'holders' => StatsFormat::int($inventory[$id] ?? [], 'holders'),
                ],
            ];
        }

        return ['boosters' => $result];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> booster id => metric => row
     */
    private function metrics(StatsContext $context): array
    {
        $rows = $this->db->all(<<<'SQL'
            SELECT booster_id, metric, SUM(qty) AS total, COALESCE(SUM(qty) FILTER (WHERE at >= :since), 0) AS period,
                   SUM(coins) AS coins_total, COALESCE(SUM(coins) FILTER (WHERE at >= :since), 0) AS coins_period
            FROM (
                SELECT booster_id, 'claims' AS metric, claimed_at AS at, 1 AS qty, 0 AS coins FROM booster_claim
                UNION ALL
                SELECT booster_id, 'openings', opened_at, 1, 0 FROM booster_opening
                UNION ALL
                SELECT booster_id, 'purchase_' || status, requested_at, 1, CASE WHEN status = 'completed' THEN price ELSE 0 END FROM booster_purchase
                UNION ALL
                SELECT c.booster_id, 'code_redemptions', r.redeemed_at, 1, 0 FROM booster_code_redemption r JOIN booster_code c ON c.id = r.booster_code_id
                UNION ALL
                SELECT c.booster_id, 'code_boosters', r.redeemed_at, r.quantity, 0 FROM booster_code_redemption r JOIN booster_code c ON c.id = r.booster_code_id
                UNION ALL
                SELECT booster_id, 'recycle_operations', recycled_at, 1, 0 FROM recycle_operation
                UNION ALL
                SELECT booster_id, 'recycle_boosters', recycled_at, booster_count, 0 FROM recycle_operation
                UNION ALL
                SELECT chosen_booster_id, 'streak_rewards', chosen_at, 1, 0 FROM streak_reward WHERE chosen_booster_id IS NOT NULL AND chosen_at IS NOT NULL
            ) channels
            GROUP BY booster_id, metric
            SQL, ['since' => $context->since]);

        $metrics = [];

        foreach ($rows as $row) {
            $metrics[StatsFormat::string($row, 'booster_id')][StatsFormat::string($row, 'metric')] = $row;
        }

        return $metrics;
    }

    /**
     * @param array<string, array<string, mixed>> $own
     *
     * @return array{total: int, period: int}
     */
    private function counter(array $own, string $metric): array
    {
        return ['total' => StatsFormat::int($own[$metric] ?? [], 'total'), 'period' => StatsFormat::int($own[$metric] ?? [], 'period')];
    }

    /**
     * @param array<string, array<string, mixed>> $own
     *
     * @return array{total: array{minor: string, coins: int}, period: array{minor: string, coins: int}}
     */
    private function coinsCounter(array $own, string $metric): array
    {
        return [
            'total' => StatsFormat::coins(StatsFormat::int($own[$metric] ?? [], 'coins_total')),
            'period' => StatsFormat::coins(StatsFormat::int($own[$metric] ?? [], 'coins_period')),
        ];
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return list<array{rarities: array<string, int>, holoChance: int, uniqueChance: int}>
     */
    private function slots(array $decoded): array
    {
        $slots = [];

        foreach ($decoded as $slot) {
            if (!\is_array($slot) || !\is_array($slot['rarities'] ?? null)) {
                continue;
            }

            $rarities = [];
            foreach ($slot['rarities'] as $rarity => $weight) {
                $rarities[(string) $rarity] = (int) $weight;
            }

            $slots[] = [
                'rarities' => $rarities,
                'holoChance' => (int) ($slot['holoChance'] ?? 0),
                'uniqueChance' => (int) ($slot['uniqueChance'] ?? 0),
            ];
        }

        return $slots;
    }

    /**
     * Same projection as Booster::getDropRates(), the player-facing percentages.
     *
     * @param list<array{rarities: array<string, int>, holoChance: int, uniqueChance: int}> $slots
     *
     * @return list<array<string, mixed>>
     */
    private function dropRates(array $slots): array
    {
        return array_map(static fn (array $slot): array => [
            'rates' => Booster::toPercentages($slot['rarities']),
            'holoChance' => $slot['holoChance'],
            'uniqueChance' => $slot['uniqueChance'],
            'uniqueRate' => Booster::toUniquePercentage($slot['uniqueChance']),
        ], $slots);
    }
}
