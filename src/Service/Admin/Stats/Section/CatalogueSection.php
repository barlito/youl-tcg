<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsFormat;

final readonly class CatalogueSection extends AbstractStatsSection
{
    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::CATALOGUE;
    }

    public function build(StatsContext $context): array
    {
        return [
            'extensions' => $this->extensions(),
            'cards' => $this->cards($context),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extensions(): array
    {
        $rows = $this->db->all(<<<'SQL'
            SELECT e.id, e.name, e.slug, e.status, e.upcoming, e.completion_reward_coins, e.visual_config,
                   e.image_name IS NOT NULL AS has_image, e.logo_name IS NOT NULL AS has_logo,
                   COALESCE((SELECT default_universe_reward_coins FROM coin_settings WHERE id = 1), 500) AS default_reward,
                   (SELECT COUNT(*) FROM extension_banner b WHERE b.extension_id = e.id) AS banners,
                   (SELECT COUNT(*) FROM booster b WHERE b.extension_id = e.id) AS boosters
            FROM extension e
            ORDER BY e.name, e.id
            SQL);

        $counts = [];
        $uniques = [];

        foreach ($this->db->all('SELECT extension_id, rarity, status, unique_flag, claimed_by IS NOT NULL AS drawn, COUNT(*) AS total FROM card GROUP BY extension_id, rarity, status, unique_flag, drawn') as $row) {
            $extension = StatsFormat::string($row, 'extension_id');
            $status = strtolower(CardStatusEnum::from(StatsFormat::int($row, 'status'))->name);
            $rarity = StatsFormat::string($row, 'rarity');
            $total = StatsFormat::int($row, 'total');
            $counts[$extension][$rarity][$status] = ($counts[$extension][$rarity][$status] ?? 0) + $total;

            if (StatsFormat::bool($row, 'unique_flag')) {
                $uniques[$extension]['total'] = ($uniques[$extension]['total'] ?? 0) + $total;
                $uniques[$extension]['published'] = ($uniques[$extension]['published'] ?? 0) + ('published' === $status ? $total : 0);
                $uniques[$extension]['drawn'] = ($uniques[$extension]['drawn'] ?? 0) + (StatsFormat::bool($row, 'drawn') ? $total : 0);
            }
        }

        $extensions = [];

        foreach ($rows as $row) {
            $id = StatsFormat::string($row, 'id');
            $override = StatsFormat::int($row, 'completion_reward_coins');
            $hasOverride = null !== ($row['completion_reward_coins'] ?? null);
            $cardCounts = [];

            foreach (CardRarityEnum::ascending() as $rarity) {
                $cardCounts[$rarity->value] = [
                    'published' => $counts[$id][$rarity->value]['published'] ?? 0,
                    'draft' => $counts[$id][$rarity->value]['draft'] ?? 0,
                ];
            }

            $extensions[] = [
                'id' => $id,
                'name' => StatsFormat::string($row, 'name'),
                'slug' => StatsFormat::string($row, 'slug'),
                'status' => strtolower(ExtensionStatusEnum::from(StatsFormat::int($row, 'status'))->name),
                'upcoming' => StatsFormat::bool($row, 'upcoming'),
                'completionRewardCoins' => StatsFormat::coins($hasOverride ? $override : StatsFormat::int($row, 'default_reward')),
                'completionRewardIsOverride' => $hasOverride,
                'visualConfig' => StatsFormat::jsonObject($row, 'visual_config'),
                'hasImage' => StatsFormat::bool($row, 'has_image'),
                'hasLogo' => StatsFormat::bool($row, 'has_logo'),
                'banners' => StatsFormat::int($row, 'banners'),
                'boosters' => StatsFormat::int($row, 'boosters'),
                'cardsByRarityAndStatus' => $cardCounts,
                'uniques' => [
                    'total' => $uniques[$id]['total'] ?? 0,
                    'published' => $uniques[$id]['published'] ?? 0,
                    'drawn' => $uniques[$id]['drawn'] ?? 0,
                ],
            ];
        }

        return $extensions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cards(StatsContext $context): array
    {
        $cards = $this->db->all(<<<'SQL'
            SELECT c.id, c.name, e.slug AS extension, c.rarity, c.status, c.unique_flag, c.always_holo, c.visual_config_override,
                   c.image_mask_name IS NOT NULL AS has_mask, c.image_foil_name IS NOT NULL AS has_foil,
                   u.discord_id AS claimed_by_id, u.username AS claimed_by_name
            FROM card c
            JOIN extension e ON e.id = c.extension_id
            LEFT JOIN discord_user u ON u.discord_id = c.claimed_by
            ORDER BY e.name, c.rarity, c.name, c.id
            SQL);

        $draws = $this->db->keyed(<<<'SQL'
            SELECT oc.card_id, SUM(oc.quantity) AS total, SUM(oc.holo_quantity) AS holo,
                   COALESCE(SUM(oc.quantity) FILTER (WHERE o.opened_at >= :since), 0) AS period,
                   MIN(o.opened_at) AS first_at, MAX(o.opened_at) AS last_at
            FROM booster_opening_card oc
            JOIN booster_opening o ON o.id = oc.booster_opening_id
            GROUP BY oc.card_id
            SQL, 'card_id', ['since' => $context->since]);

        $holders = $this->db->keyed(<<<'SQL'
            SELECT card_id, COUNT(*) FILTER (WHERE quantity > 0) AS holders, COALESCE(SUM(quantity), 0) AS copies, COALESCE(SUM(holo_quantity), 0) AS holo_copies
            FROM user_card
            GROUP BY card_id
            SQL, 'card_id');

        $listings = $this->db->keyed(
            'SELECT card_id, COUNT(*) AS total FROM market_listing WHERE status = :active GROUP BY card_id',
            'card_id',
            ['active' => MarketListingStatusEnum::ACTIVE->value],
        );

        $sales = $this->db->keyed(<<<'SQL'
            SELECT l.card_id, COUNT(*) AS total, AVG(p.price) AS average, MIN(p.price) AS min_price, MAX(p.price) AS max_price,
                   COUNT(*) FILTER (WHERE p.requested_at >= :since) AS period
            FROM market_purchase p
            JOIN market_listing l ON l.id = p.listing_id
            WHERE p.status IN (:transferred, :completed)
            GROUP BY l.card_id
            SQL, 'card_id', [
            'since' => $context->since,
            'transferred' => MarketPurchaseStatusEnum::CARD_TRANSFERRED->value,
            'completed' => MarketPurchaseStatusEnum::COMPLETED->value,
        ]);

        $trades = $this->db->keyed(
            'SELECT l.card_id, COUNT(DISTINCT l.trade_offer_id) AS total FROM trade_offer_line l JOIN trade_offer o ON o.id = l.trade_offer_id WHERE o.status = :accepted GROUP BY l.card_id',
            'card_id',
            ['accepted' => TradeOfferStatusEnum::ACCEPTED->value],
        );

        $recycled = $this->db->keyed('SELECT card_id, SUM(quantity) AS copies FROM recycle_operation_card GROUP BY card_id', 'card_id');

        $result = [];

        foreach ($cards as $row) {
            $id = StatsFormat::string($row, 'id');
            $draw = $draws[$id] ?? [];
            $holder = $holders[$id] ?? [];
            $sale = $sales[$id] ?? [];
            $copies = StatsFormat::int($holder, 'copies');
            $holoCopies = StatsFormat::int($holder, 'holo_copies');

            $result[] = [
                'id' => $id,
                'name' => StatsFormat::string($row, 'name'),
                'extension' => StatsFormat::string($row, 'extension'),
                'rarity' => StatsFormat::string($row, 'rarity'),
                'status' => strtolower(CardStatusEnum::from(StatsFormat::int($row, 'status'))->name),
                'unique' => StatsFormat::bool($row, 'unique_flag'),
                'claimedBy' => $this->player(StatsFormat::nullableString($row, 'claimed_by_id'), StatsFormat::nullableString($row, 'claimed_by_name')),
                'alwaysHolo' => StatsFormat::bool($row, 'always_holo'),
                'visualConfigOverride' => StatsFormat::jsonObject($row, 'visual_config_override'),
                'hasMask' => StatsFormat::bool($row, 'has_mask'),
                'hasFoil' => StatsFormat::bool($row, 'has_foil'),
                'draws' => [
                    'total' => StatsFormat::int($draw, 'total'),
                    'holo' => StatsFormat::int($draw, 'holo'),
                    'period' => StatsFormat::int($draw, 'period'),
                    'firstAt' => StatsFormat::iso($draw, 'first_at'),
                    'lastAt' => StatsFormat::iso($draw, 'last_at'),
                ],
                'holders' => StatsFormat::int($holder, 'holders'),
                'copiesInCirculation' => ['total' => $copies, 'normal' => $copies - $holoCopies, 'holo' => $holoCopies],
                'activeListings' => StatsFormat::int($listings[$id] ?? [], 'total'),
                'marketSales' => [
                    'count' => StatsFormat::int($sale, 'total'),
                    'countPeriod' => StatsFormat::int($sale, 'period'),
                    'averagePriceCoins' => null === ($sale['average'] ?? null) ? null : round(StatsFormat::float($sale, 'average'), 2),
                    'minPriceCoins' => null === ($sale['min_price'] ?? null) ? null : StatsFormat::int($sale, 'min_price'),
                    'maxPriceCoins' => null === ($sale['max_price'] ?? null) ? null : StatsFormat::int($sale, 'max_price'),
                ],
                'timesTraded' => StatsFormat::int($trades[$id] ?? [], 'total'),
                'copiesRecycled' => StatsFormat::int($recycled[$id] ?? [], 'copies'),
            ];
        }

        return $result;
    }
}
