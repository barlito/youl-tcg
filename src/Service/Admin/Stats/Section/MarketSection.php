<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsFormat;
use App\Service\Admin\Stats\StatsMath;

final readonly class MarketSection extends AbstractStatsSection
{
    private const int TOP = 10;

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::MARKET;
    }

    public function build(StatsContext $context): array
    {
        return [
            'listingsByStatus' => $this->byStatus('market_listing', MarketListingStatusEnum::cases(), 'created_at', $context),
            'activeListings' => $this->activeListings(),
            'sales' => $this->sales($context),
            'purchasesByStatus' => $this->byStatus('market_purchase', MarketPurchaseStatusEnum::cases(), 'requested_at', $context),
            'topCards' => $this->tops($context, 'c.id, c.name, c.rarity', 'card', 'JOIN card c ON c.id = l.card_id', static fn (array $row): array => [
                'id' => StatsFormat::string($row, 'id'), 'name' => StatsFormat::string($row, 'name'), 'rarity' => StatsFormat::string($row, 'rarity'),
            ]),
            'topSellers' => $this->tops($context, 'u.discord_id, u.username', 'seller', 'JOIN discord_user u ON u.discord_id = p.seller_id', fn (array $row): array => $this->player(StatsFormat::string($row, 'discord_id'), StatsFormat::string($row, 'username')) ?? []),
            'topBuyers' => $this->tops($context, 'u.discord_id, u.username', 'buyer', 'JOIN discord_user u ON u.discord_id = p.buyer_id', fn (array $row): array => $this->player(StatsFormat::string($row, 'discord_id'), StatsFormat::string($row, 'username')) ?? []),
        ];
    }

    /**
     * @param list<MarketListingStatusEnum|MarketPurchaseStatusEnum> $cases
     *
     * @return array<string, array{total: int, period: int}>
     */
    private function byStatus(string $table, array $cases, string $periodColumn, StatsContext $context): array
    {
        $rows = $this->db->keyed(
            \sprintf('SELECT status, COUNT(*) AS total, COUNT(*) FILTER (WHERE %s >= :since) AS period FROM %s GROUP BY status', $periodColumn, $table),
            'status',
            ['since' => $context->since],
        );

        $result = [];

        foreach ($cases as $case) {
            $result[$case->value] = ['total' => StatsFormat::int($rows[$case->value] ?? [], 'total'), 'period' => StatsFormat::int($rows[$case->value] ?? [], 'period')];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function activeListings(): array
    {
        $rows = $this->db->all(
            'SELECT c.rarity, l.holo, json_agg(l.price) AS prices FROM market_listing l JOIN card c ON c.id = l.card_id WHERE l.status = :active GROUP BY c.rarity, l.holo',
            ['active' => MarketListingStatusEnum::ACTIVE->value],
        );

        $byRarity = [];

        foreach ($rows as $row) {
            $byRarity[StatsFormat::string($row, 'rarity')][StatsFormat::bool($row, 'holo') ? 'holo' : 'normal'] = $this->prices(StatsFormat::jsonList($row, 'prices'));
        }

        $result = [];

        foreach (CardRarityEnum::ascending() as $rarity) {
            $normal = $byRarity[$rarity->value]['normal'] ?? [];
            $holo = $byRarity[$rarity->value]['holo'] ?? [];
            $result[$rarity->value] = [
                'normal' => $this->priceSummary($normal),
                'holo' => $this->priceSummary($holo),
                'all' => $this->priceSummary([...$normal, ...$holo]),
            ];
        }

        $total = array_sum(array_map(static fn (array $entry): int => $entry['all']['count'], $result));

        return ['total' => $total, 'byRarity' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function sales(StatsContext $context): array
    {
        $rows = $this->db->all(<<<'SQL'
            SELECT c.rarity, l.holo,
                   json_agg(p.price) AS prices,
                   json_agg(p.price) FILTER (WHERE p.requested_at >= :since) AS period_prices,
                   json_agg(EXTRACT(EPOCH FROM (p.requested_at - l.created_at))) AS delays,
                   json_agg(EXTRACT(EPOCH FROM (p.requested_at - l.created_at))) FILTER (WHERE p.requested_at >= :since) AS period_delays
            FROM market_purchase p
            JOIN market_listing l ON l.id = p.listing_id
            JOIN card c ON c.id = l.card_id
            WHERE p.status IN (:transferred, :completed)
            GROUP BY c.rarity, l.holo
            SQL, $this->saleParameters($context));

        $prices = ['allTime' => [], 'period' => []];
        $delays = ['allTime' => [], 'period' => []];
        $byRarity = [];

        foreach ($rows as $row) {
            $rarity = StatsFormat::string($row, 'rarity');
            $finish = StatsFormat::bool($row, 'holo') ? 'holo' : 'normal';
            $all = $this->prices(StatsFormat::jsonList($row, 'prices'));
            $period = $this->prices(StatsFormat::jsonList($row, 'period_prices'));
            $byRarity[$rarity][$finish] = ['allTime' => $all, 'period' => $period];
            $prices['allTime'] = [...$prices['allTime'], ...$all];
            $prices['period'] = [...$prices['period'], ...$period];
            $delays['allTime'] = [...$delays['allTime'], ...$this->prices(StatsFormat::jsonList($row, 'delays'))];
            $delays['period'] = [...$delays['period'], ...$this->prices(StatsFormat::jsonList($row, 'period_delays'))];
        }

        $distribution = [];

        foreach (CardRarityEnum::ascending() as $rarity) {
            foreach (['normal', 'holo'] as $finish) {
                $entry = $byRarity[$rarity->value][$finish] ?? ['allTime' => [], 'period' => []];
                $distribution[$rarity->value][$finish] = ['allTime' => $this->priceSummary($entry['allTime']), 'period' => $this->priceSummary($entry['period'])];
            }
        }

        return [
            'note' => 'a sale is a purchase in card_transferred or completed; delay = listing creation to purchase request',
            'priceCoins' => ['allTime' => $this->priceSummary($prices['allTime']), 'period' => $this->priceSummary($prices['period'])],
            'priceCoinsByRarityAndFinish' => $distribution,
            'delaySeconds' => ['allTime' => $this->delaySummary($delays['allTime']), 'period' => $this->delaySummary($delays['period'])],
        ];
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<int|float>
     */
    private function prices(array $values): array
    {
        return array_values(array_map(static fn (mixed $value): int | float => is_numeric($value) ? $value + 0 : 0, $values));
    }

    /**
     * @param list<int|float> $prices
     *
     * @return array{count: int, min: int|float|null, median: float|null, max: int|float|null, average: float|null}
     */
    private function priceSummary(array $prices): array
    {
        return StatsMath::summary($prices);
    }

    /**
     * @param list<int|float> $delays
     *
     * @return array{count: int, average: float|null, median: float|null, max: int|float|null}
     */
    private function delaySummary(array $delays): array
    {
        return [
            'count' => \count($delays),
            'average' => StatsMath::average($delays),
            'median' => StatsMath::median($delays),
            'max' => [] === $delays ? null : max($delays),
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $identity
     *
     * @return array{allTime: list<array<string, mixed>>, period: list<array<string, mixed>>}
     */
    private function tops(StatsContext $context, string $columns, string $key, string $join, callable $identity): array
    {
        $result = [];

        foreach (['allTime' => '', 'period' => ' AND p.requested_at >= :since'] as $scope => $filter) {
            $sql = \sprintf(
                'SELECT %1$s, COUNT(*) AS sales, SUM(p.price) AS volume, AVG(p.price) AS average FROM market_purchase p JOIN market_listing l ON l.id = p.listing_id %2$s WHERE p.status IN (:transferred, :completed)%3$s GROUP BY %1$s ORDER BY sales DESC, volume DESC, %4$s LIMIT %5$d',
                $columns,
                $join,
                $filter,
                explode(',', $columns)[0],
                self::TOP,
            );

            $result[$scope] = array_map(static fn (array $row): array => [
                $key => $identity($row),
                'sales' => StatsFormat::int($row, 'sales'),
                'volume' => StatsFormat::coins(StatsFormat::int($row, 'volume')),
                'averagePriceCoins' => round(StatsFormat::float($row, 'average'), 2),
            ], $this->db->all($sql, $this->saleParameters($context)));
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function saleParameters(StatsContext $context): array
    {
        return [
            'since' => $context->since,
            'transferred' => MarketPurchaseStatusEnum::CARD_TRANSFERRED->value,
            'completed' => MarketPurchaseStatusEnum::COMPLETED->value,
        ];
    }
}
