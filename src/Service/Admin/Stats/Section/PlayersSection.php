<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Service\Admin\EconomyStatsProvider;
use App\Service\Admin\Stats\PlayerDays;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;
use App\Service\Admin\Stats\StreakCalculator;

final readonly class PlayersSection extends AbstractStatsSection
{
    private const string EPOCH = "TIMESTAMP '1970-01-01'";

    public function __construct(StatsDb $db, private EconomyStatsProvider $economy, private PlayerDays $playerDays)
    {
        parent::__construct($db);
    }

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::PLAYERS;
    }

    public function build(StatsContext $context): array
    {
        $users = $this->db->all('SELECT discord_id, username, roles, created_at FROM discord_user ORDER BY username, discord_id');
        $metrics = $this->metrics($context);
        $activity = $this->db->keyed($this->activityQuery(), 'user_id', ['since' => $context->since]);
        $cards = $this->db->keyed('SELECT discord_user_id, COUNT(*) FILTER (WHERE quantity > 0) AS distinct_cards, COALESCE(SUM(quantity), 0) AS copies, COALESCE(SUM(holo_quantity), 0) AS holos FROM user_card GROUP BY discord_user_id', 'discord_user_id');
        $uniques = $this->db->keyed('SELECT claimed_by, COUNT(*) AS total FROM card WHERE unique_flag AND claimed_by IS NOT NULL GROUP BY claimed_by', 'claimed_by');
        $listings = $this->db->keyed(
            'SELECT seller_id, COUNT(*) FILTER (WHERE status = :active) AS active, COUNT(*) FILTER (WHERE status IN (:active, :reserved)) AS engaged FROM market_listing GROUP BY seller_id',
            'seller_id',
            ['active' => MarketListingStatusEnum::ACTIVE->value, 'reserved' => MarketListingStatusEnum::RESERVED_FOR_PURCHASE->value],
        );
        $unopened = $this->db->keyed('SELECT discord_user_id, COALESCE(SUM(quantity), 0) AS copies, COUNT(*) FILTER (WHERE quantity > 0) AS distinct_boosters FROM user_booster GROUP BY discord_user_id', 'discord_user_id');
        $unread = $this->db->keyed($this->unreadQuery(), 'discord_id');
        $wishes = $this->db->keyed('SELECT player_id, COUNT(*) AS total FROM wishlist_entry GROUP BY player_id', 'player_id');
        $watches = $this->db->keyed('SELECT player_id, COUNT(*) AS total FROM wishlist_universe GROUP BY player_id', 'player_id');
        $alerts = $this->db->keyed('SELECT player_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE created_at >= :since) AS period FROM wishlist_alert GROUP BY player_id', 'player_id', ['since' => $context->since]);
        $completion = $this->completion();
        $openingDays = $this->playerDays->openings();

        $players = [];

        foreach ($users as $user) {
            $id = StatsFormat::string($user, 'discord_id');
            $own = $metrics[$id] ?? [];
            $seen = $activity[$id] ?? [];
            $card = $cards[$id] ?? [];
            $streak = StreakCalculator::analyse($openingDays[$id] ?? [], $context->today);
            $unreadRow = $unread[$id] ?? [];

            $players[] = [
                'discordId' => $id,
                'username' => StatsFormat::string($user, 'username'),
                'roles' => array_values(StatsFormat::json($user, 'roles')),
                'registeredAt' => StatsFormat::iso($user, 'created_at'),
                'firstActivityAt' => StatsFormat::iso($seen, 'first_at'),
                'lastActivityAt' => StatsFormat::iso($seen, 'last_at'),
                'activeDays' => ['period' => StatsFormat::int($seen, 'active_days_period'), 'total' => StatsFormat::int($seen, 'active_days')],
                'openings' => $this->pair($own, 'openings'),
                'claims' => $this->pair($own, 'claims'),
                'boosterPurchases' => [
                    'completed' => $this->pair($own, 'purchase_completed'),
                    'pending' => $this->pair($own, 'purchase_pending'),
                    'failed' => $this->pair($own, 'purchase_failed'),
                    'coinsSpent' => $this->money($own, 'purchase_completed'),
                ],
                'codesUsed' => ['redemptions' => $this->pair($own, 'code_redemptions'), 'boostersGranted' => $this->pair($own, 'code_boosters')],
                'cards' => [
                    'distinct' => StatsFormat::int($card, 'distinct_cards'),
                    'copies' => StatsFormat::int($card, 'copies'),
                    'holoCopies' => StatsFormat::int($card, 'holos'),
                    'uniquesHeld' => StatsFormat::int($uniques[$id] ?? [], 'total'),
                ],
                'completion' => $completion($id),
                'market' => [
                    'sales' => ['count' => $this->pair($own, 'market_sales'), 'volume' => $this->money($own, 'market_sales'), 'feesPaid' => $this->minor($own, 'market_sales')],
                    'purchases' => ['count' => $this->pair($own, 'market_purchases'), 'volume' => $this->money($own, 'market_purchases')],
                    'activeListings' => StatsFormat::int($listings[$id] ?? [], 'active'),
                    'engagedListings' => StatsFormat::int($listings[$id] ?? [], 'engaged'),
                ],
                'trades' => [
                    'proposed' => $this->pair($own, 'trade_proposed'),
                    'received' => $this->pair($own, 'trade_received'),
                    'accepted' => $this->pair($own, 'trade_accepted'),
                    'refusedByPlayer' => $this->pair($own, 'trade_refused_by_player'),
                    'proposedAndRefused' => $this->pair($own, 'trade_proposed_refused'),
                ],
                'recycling' => [
                    'operations' => $this->pair($own, 'recycle_operations'),
                    'points' => $this->pair($own, 'recycle_points'),
                    'boostersObtained' => $this->pair($own, 'recycle_boosters'),
                ],
                'fusion' => [
                    'operations' => $this->pair($own, 'fusion_operations'),
                    'fusions' => $this->pair($own, 'fusion_count'),
                    'copiesConsumed' => $this->pair($own, 'fusion_copies'),
                    'holosCreated' => $this->pair($own, 'fusion_holos'),
                ],
                'streak' => [
                    'current' => $streak['current'],
                    'best' => $streak['best'],
                    'startedOn' => $streak['startedOn'],
                    'openedToday' => $streak['openedToday'],
                    'milestonesAwarded' => StatsFormat::int($own['streak_awarded'] ?? [], 'total'),
                    'rewardsChosen' => StatsFormat::int($own['streak_chosen'] ?? [], 'total'),
                ],
                'universeRewards' => [
                    'paid' => ['count' => StatsFormat::int($own['reward_paid'] ?? [], 'total'), 'amount' => StatsFormat::coins(StatsFormat::int($own['reward_paid'] ?? [], 'coins'))],
                    'pending' => ['count' => StatsFormat::int($own['reward_pending'] ?? [], 'total'), 'amount' => StatsFormat::coins(StatsFormat::int($own['reward_pending'] ?? [], 'coins'))],
                    'failed' => ['count' => StatsFormat::int($own['reward_failed'] ?? [], 'total'), 'amount' => StatsFormat::coins(StatsFormat::int($own['reward_failed'] ?? [], 'coins'))],
                ],
                'wishlist' => [
                    'entries' => StatsFormat::int($wishes[$id] ?? [], 'total'),
                    'universesWatched' => StatsFormat::int($watches[$id] ?? [], 'total'),
                    'alertsReceived' => $this->pair(['alerts' => $alerts[$id] ?? []], 'alerts'),
                ],
                'unreadNotifications' => [
                    'personal' => StatsFormat::int($unreadRow, 'personal'),
                    'broadcast' => StatsFormat::int($unreadRow, 'broadcast'),
                    'total' => StatsFormat::int($unreadRow, 'personal') + StatsFormat::int($unreadRow, 'broadcast'),
                ],
                'unopenedBoosters' => ['copies' => StatsFormat::int($unopened[$id] ?? [], 'copies'), 'distinct' => StatsFormat::int($unopened[$id] ?? [], 'distinct_boosters')],
            ];
        }

        return ['count' => \count($players), 'players' => $players];
    }

    /**
     * @param array<string, array<string, mixed>> $own
     *
     * @return array{total: int, period: int}
     */
    private function pair(array $own, string $metric): array
    {
        return ['total' => StatsFormat::int($own[$metric] ?? [], 'total'), 'period' => StatsFormat::int($own[$metric] ?? [], 'period')];
    }

    /**
     * @param array<string, array<string, mixed>> $own
     *
     * @return array{total: array{minor: string, coins: int}, period: array{minor: string, coins: int}}
     */
    private function money(array $own, string $metric): array
    {
        return ['total' => StatsFormat::coins(StatsFormat::int($own[$metric] ?? [], 'coins')), 'period' => StatsFormat::coins(StatsFormat::int($own[$metric] ?? [], 'period_coins'))];
    }

    /**
     * @param array<string, array<string, mixed>> $own
     *
     * @return array{total: array{minor: string, coins: int}, period: array{minor: string, coins: int}}
     */
    private function minor(array $own, string $metric): array
    {
        return ['total' => StatsFormat::minor(StatsFormat::string($own[$metric] ?? [], 'minor') ?: '0'), 'period' => StatsFormat::minor(StatsFormat::string($own[$metric] ?? [], 'period_minor') ?: '0')];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> player => metric => row
     */
    private function metrics(StatsContext $context): array
    {
        $rows = $this->db->all(<<<'SQL'
            SELECT user_id, metric, SUM(qty) AS total, COALESCE(SUM(qty) FILTER (WHERE at >= :since), 0) AS period,
                   SUM(coins) AS coins, COALESCE(SUM(coins) FILTER (WHERE at >= :since), 0) AS period_coins,
                   SUM(minor) AS minor, COALESCE(SUM(minor) FILTER (WHERE at >= :since), 0) AS period_minor
            FROM (
                SELECT discord_user_id AS user_id, 'openings' AS metric, opened_at AS at, 1 AS qty, 0 AS coins, 0::bigint AS minor FROM booster_opening
                UNION ALL
                SELECT discord_user_id, 'claims', claimed_at, 1, 0, 0 FROM booster_claim
                UNION ALL
                SELECT discord_user_id, 'purchase_' || status, requested_at, 1, CASE WHEN status = 'completed' THEN price ELSE 0 END, 0 FROM booster_purchase
                UNION ALL
                SELECT discord_user_id, 'code_redemptions', redeemed_at, 1, 0, 0 FROM booster_code_redemption
                UNION ALL
                SELECT discord_user_id, 'code_boosters', redeemed_at, quantity, 0, 0 FROM booster_code_redemption
                UNION ALL
                SELECT discord_user_id, 'recycle_operations', recycled_at, 1, 0, 0 FROM recycle_operation
                UNION ALL
                SELECT discord_user_id, 'recycle_points', recycled_at, points, 0, 0 FROM recycle_operation
                UNION ALL
                SELECT discord_user_id, 'recycle_boosters', recycled_at, booster_count, 0, 0 FROM recycle_operation
                UNION ALL
                SELECT discord_user_id, 'fusion_operations', fused_at, 1, 0, 0 FROM fusion_operation
                UNION ALL
                SELECT discord_user_id, 'fusion_count', fused_at, fusion_count, 0, 0 FROM fusion_operation
                UNION ALL
                SELECT discord_user_id, 'fusion_copies', fused_at, copies_consumed, 0, 0 FROM fusion_operation
                UNION ALL
                SELECT discord_user_id, 'fusion_holos', fused_at, holos_created, 0, 0 FROM fusion_operation
                UNION ALL
                SELECT proposer_id, 'trade_proposed', created_at, 1, 0, 0 FROM trade_offer
                UNION ALL
                SELECT receiver_id, 'trade_received', created_at, 1, 0, 0 FROM trade_offer
                UNION ALL
                SELECT proposer_id, 'trade_accepted', resolved_at, 1, 0, 0 FROM trade_offer WHERE status = 'accepted'
                UNION ALL
                SELECT receiver_id, 'trade_accepted', resolved_at, 1, 0, 0 FROM trade_offer WHERE status = 'accepted'
                UNION ALL
                SELECT receiver_id, 'trade_refused_by_player', resolved_at, 1, 0, 0 FROM trade_offer WHERE status = 'refused'
                UNION ALL
                SELECT proposer_id, 'trade_proposed_refused', resolved_at, 1, 0, 0 FROM trade_offer WHERE status = 'refused'
                UNION ALL
                SELECT seller_id, 'market_sales', requested_at, 1, price, fee_minor FROM market_purchase WHERE status IN ('card_transferred', 'completed')
                UNION ALL
                SELECT buyer_id, 'market_purchases', requested_at, 1, price, 0 FROM market_purchase WHERE status IN ('card_transferred', 'completed')
                UNION ALL
                SELECT discord_user_id, 'streak_awarded', awarded_at, 1, 0, 0 FROM streak_reward
                UNION ALL
                SELECT discord_user_id, 'streak_chosen', chosen_at, 1, 0, 0 FROM streak_reward WHERE chosen_at IS NOT NULL
                UNION ALL
                SELECT discord_user_id, 'reward_paid', paid_at, 1, amount, 0 FROM universe_completion_reward WHERE status = 'paid' AND paid_at IS NOT NULL
                UNION ALL
                SELECT discord_user_id, 'reward_pending', completed_at, 1, amount, 0 FROM universe_completion_reward WHERE status = 'pending'
                UNION ALL
                SELECT discord_user_id, 'reward_failed', completed_at, 1, amount, 0 FROM universe_completion_reward WHERE status = 'failed'
            ) events
            GROUP BY user_id, metric
            SQL, ['since' => $context->since]);

        $metrics = [];

        foreach ($rows as $row) {
            $metrics[StatsFormat::string($row, 'user_id')][StatsFormat::string($row, 'metric')] = $row;
        }

        return $metrics;
    }

    private function activityQuery(): string
    {
        return \sprintf(
            'SELECT user_id, MIN(happened_at) AS first_at, MAX(happened_at) AS last_at, COUNT(DISTINCT day) AS active_days, COUNT(DISTINCT day) FILTER (WHERE happened_at >= :since) AS active_days_period FROM (SELECT user_id, happened_at, %s AS day FROM (%s) activity) days GROUP BY user_id',
            $this->db->day('happened_at'),
            $this->economy->activitySql(self::EPOCH),
        );
    }

    private function unreadQuery(): string
    {
        return <<<'SQL'
            SELECT u.discord_id,
                   (SELECT COUNT(*) FROM notification n WHERE n.recipient_id = u.discord_id AND n.read_at IS NULL) AS personal,
                   (SELECT COUNT(*) FROM notification b
                    WHERE b.recipient_id IS NULL
                      AND b.created_at > COALESCE(u.notifications_seen_at, u.created_at)
                      AND NOT EXISTS (SELECT 1 FROM notification_broadcast_read r WHERE r.notification_id = b.id AND r.discord_user_id = u.discord_id)) AS broadcast
            FROM discord_user u
            SQL;
    }

    /**
     * Published catalogue only (card AND extension published), like the leaderboard.
     *
     * @return \Closure(string): array<string, mixed>
     */
    private function completion(): \Closure
    {
        $totals = $this->db->all(
            'SELECT e.slug, COUNT(c.id) AS total FROM extension e JOIN card c ON c.extension_id = e.id AND c.status = :cardPublished WHERE e.status = :extensionPublished GROUP BY e.slug ORDER BY e.slug',
            ['cardPublished' => CardStatusEnum::PUBLISHED->value, 'extensionPublished' => ExtensionStatusEnum::PUBLISHED->value],
        );

        $ownedRows = $this->db->all(
            'SELECT uc.discord_user_id, e.slug, COUNT(*) AS owned FROM user_card uc JOIN card c ON c.id = uc.card_id AND c.status = :cardPublished JOIN extension e ON e.id = c.extension_id AND e.status = :extensionPublished WHERE uc.quantity > 0 GROUP BY uc.discord_user_id, e.slug',
            ['cardPublished' => CardStatusEnum::PUBLISHED->value, 'extensionPublished' => ExtensionStatusEnum::PUBLISHED->value],
        );

        $owned = [];
        foreach ($ownedRows as $row) {
            $owned[StatsFormat::string($row, 'discord_user_id')][StatsFormat::string($row, 'slug')] = StatsFormat::int($row, 'owned');
        }

        $grandTotal = array_sum(array_map(static fn (array $row): int => StatsFormat::int($row, 'total'), $totals));

        return static function (string $playerId) use ($totals, $owned, $grandTotal): array {
            $universes = [];
            $distinct = 0;

            foreach ($totals as $row) {
                $slug = StatsFormat::string($row, 'slug');
                $total = StatsFormat::int($row, 'total');
                $count = $owned[$playerId][$slug] ?? 0;
                $distinct += $count;
                $universes[] = ['extension' => $slug, 'owned' => $count, 'total' => $total, 'percent' => StatsFormat::share($count, $total)];
            }

            return ['globalPercent' => StatsFormat::share($distinct, $grandTotal), 'publishedOwned' => $distinct, 'publishedTotal' => $grandTotal, 'universes' => $universes];
        };
    }
}
