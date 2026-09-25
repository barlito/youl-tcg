<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\EconomyDashboard;
use App\Dto\Admin\EconomyKpis;
use App\Dto\Admin\RarityComparison;
use App\Dto\Admin\RarityComparisonRow;
use App\Dto\Admin\WeeklyRecycleStats;
use App\Entity\Booster;
use App\Enum\Admin\BoosterChannelEnum;
use App\Enum\Admin\EconomyPeriodEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Service\Booster\CardDrawer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Admin economy dashboard. Native SQL aggregates only (one grouped query per
 * indicator, never one per day or per player). Timestamps are stored UTC and
 * bucketed into Europe/Paris days at the SQL boundary, like
 * BoosterOpeningRepository::findDistinctOpeningDays.
 */
final readonly class EconomyStatsProvider
{
    public const string TIMEZONE = 'Europe/Paris';

    public const string UNIQUE_BUCKET = 'unique';

    /**
     * What makes a player « active »: table => timestamp column (the player
     * column is always discord_user_id). Extension point: add
     * 'trade_offer' => '<its timestamp>' here once trades land.
     */
    private const array ACTIVITY_SOURCES = [
        'booster_opening' => 'opened_at',
        'booster_claim' => 'claimed_at',
    ];

    private const string UTC_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function getDashboard(EconomyPeriodEnum $period): EconomyDashboard
    {
        $start = $this->today()->modify(\sprintf('-%d days', $period->value - 1));
        $since = $this->toUtc($start);
        $days = $this->listDays($start);

        return new EconomyDashboard(
            $period,
            $this->clock->now(),
            $this->getKpis(),
            $days,
            $this->fillDays($days, $this->countOpeningsPerDay($since)),
            $this->fillDays($days, $this->countActivePlayersPerDay($since)),
            $this->countBoostersPerChannel($days, $since),
            $this->aggregateWeeklyRecycles($start, $since),
            $this->compareRarities($since),
        );
    }

    public function getKpis(): EconomyKpis
    {
        $today = $this->today();

        $sql = \sprintf(<<<'SQL'
            WITH activity AS (%s)
            SELECT *
            FROM (SELECT COUNT(*) AS registered_players FROM discord_user) players,
                 (SELECT COUNT(DISTINCT user_id) FILTER (WHERE happened_at >= :since7) AS active_7,
                         COUNT(DISTINCT user_id) AS active_30
                  FROM activity) active,
                 (SELECT COUNT(*) FILTER (WHERE opened_at >= :sinceToday) AS openings_today,
                         COUNT(*) FILTER (WHERE opened_at >= :since7) AS openings_7,
                         COUNT(*) AS openings_total
                  FROM booster_opening) openings,
                 (SELECT COALESCE(SUM(quantity), 0) AS cards_drawn,
                         COALESCE(SUM(holo_quantity), 0) AS holos_drawn
                  FROM booster_opening_card) drawn,
                 (SELECT COUNT(*) FILTER (WHERE claimed_by IS NOT NULL) AS uniques_drawn,
                         COUNT(*) AS uniques_total
                  FROM card
                  WHERE unique_flag AND (status = :published OR claimed_by IS NOT NULL)) uniques,
                 (SELECT COALESCE(SUM(quantity), 0) AS unopened FROM user_booster) inventory
            SQL, $this->activitySql(':since30'));

        $row = $this->connection->fetchAssociative($sql, [
            'sinceToday' => $this->toUtc($today),
            'since7' => $this->toUtc($today->modify('-6 days')),
            'since30' => $this->toUtc($today->modify('-29 days')),
            'published' => CardStatusEnum::PUBLISHED->value,
        ]) ?: [];

        return new EconomyKpis(
            $this->int($row, 'registered_players'),
            $this->int($row, 'active_7'),
            $this->int($row, 'active_30'),
            $this->int($row, 'openings_today'),
            $this->int($row, 'openings_7'),
            $this->int($row, 'openings_total'),
            $this->int($row, 'cards_drawn'),
            $this->int($row, 'holos_drawn'),
            $this->int($row, 'uniques_drawn'),
            $this->int($row, 'uniques_total'),
            $this->int($row, 'unopened'),
        );
    }

    /**
     * @return array<string, int>
     */
    private function countOpeningsPerDay(string $since): array
    {
        $sql = \sprintf(
            'SELECT %s AS day, COUNT(*) AS total FROM booster_opening WHERE opened_at >= :since GROUP BY day',
            $this->dayExpression('opened_at'),
        );

        return $this->fetchDayTotals($sql, $since);
    }

    /**
     * @return array<string, int>
     */
    private function countActivePlayersPerDay(string $since): array
    {
        $sql = \sprintf(
            'SELECT %s AS day, COUNT(DISTINCT user_id) AS total FROM (%s) activity GROUP BY day',
            $this->dayExpression('happened_at'),
            $this->activitySql(':since'),
        );

        return $this->fetchDayTotals($sql, $since);
    }

    /**
     * Boosters credited per channel and day. A claim and a chosen streak
     * reward credit one booster; a code redemption its quantity; a recycling
     * its boosterCount.
     *
     * @param list<string> $days
     *
     * @return array<string, array<string, int>>
     */
    private function countBoostersPerChannel(array $days, string $since): array
    {
        $sql = \sprintf(
            <<<'SQL'
                SELECT channel, day, SUM(boosters) AS total FROM (
                    SELECT :claim AS channel, %s AS day, 1 AS boosters FROM booster_claim WHERE claimed_at >= :since
                    UNION ALL
                    SELECT :code, %s, quantity FROM booster_code_redemption WHERE redeemed_at >= :since
                    UNION ALL
                    SELECT :streak, %s, 1 FROM streak_reward WHERE chosen_booster_id IS NOT NULL AND chosen_at >= :since
                    UNION ALL
                    SELECT :recycle, %s, booster_count FROM recycle_operation WHERE recycled_at >= :since
                ) distributed
                GROUP BY channel, day
                SQL,
            $this->dayExpression('claimed_at'),
            $this->dayExpression('redeemed_at'),
            $this->dayExpression('chosen_at'),
            $this->dayExpression('recycled_at'),
        );

        $rows = $this->connection->fetchAllAssociative($sql, [
            'timezone' => self::TIMEZONE,
            'since' => $since,
            'claim' => BoosterChannelEnum::DAILY_CLAIM->value,
            'code' => BoosterChannelEnum::CODE->value,
            'streak' => BoosterChannelEnum::STREAK->value,
            'recycle' => BoosterChannelEnum::RECYCLE->value,
        ]);

        $perChannel = [];

        foreach (BoosterChannelEnum::cases() as $channel) {
            $perChannel[$channel->value] = array_fill_keys($days, 0);
        }

        foreach ($rows as $row) {
            $channel = $this->string($row, 'channel');
            $day = $this->string($row, 'day');

            if (isset($perChannel[$channel][$day])) {
                $perChannel[$channel][$day] = $this->int($row, 'total');
            }
        }

        return $perChannel;
    }

    /**
     * @return list<WeeklyRecycleStats>
     */
    private function aggregateWeeklyRecycles(\DateTimeImmutable $start, string $since): array
    {
        $sql = <<<'SQL'
            SELECT to_char(date_trunc('week', (recycled_at AT TIME ZONE 'UTC') AT TIME ZONE :timezone), 'YYYY-MM-DD') AS week,
                   COUNT(*) AS operations,
                   SUM(points) AS points,
                   SUM(booster_count) AS boosters
            FROM recycle_operation
            WHERE recycled_at >= :since
            GROUP BY week
            SQL;

        $perWeek = [];

        foreach ($this->connection->fetchAllAssociative($sql, ['timezone' => self::TIMEZONE, 'since' => $since]) as $row) {
            $perWeek[$this->string($row, 'week')] = $row;
        }

        $weeks = [];
        $week = $start->modify('monday this week');
        $today = $this->today();

        while ($week <= $today) {
            $key = $week->format('Y-m-d');
            $row = $perWeek[$key] ?? [];
            $weeks[] = new WeeklyRecycleStats($key, $this->int($row, 'operations'), $this->int($row, 'points'), $this->int($row, 'boosters'));
            $week = $week->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * Observed buckets (tiers of regular cards + the 1/1 bucket) versus the
     * rates of the boosters opened in the period, each slot weighing as much
     * as the openings of its booster. Expected side, per slot: uniqueChance
     * goes to the 1/1 bucket, the rest is split along the rarity weights,
     * each tier redirected with the draw's own fallback rule
     * (CardDrawer::nearestAvailableRarity) over the tiers the extension has
     * TODAY. Approximations: current rarityRates and current card pool (not
     * those of the opening time), uniqueChance counted even once every 1/1 of
     * the extension is drawn, alwaysHolo cards not modelled in the holo rate.
     */
    private function compareRarities(string $since): RarityComparison
    {
        $observed = $this->fetchObservedBuckets($since);
        $openedBoosters = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT booster.extension_id, booster.rarity_rates, COUNT(booster_opening.id) AS openings
            FROM booster_opening
            JOIN booster ON booster.id = booster_opening.booster_id
            WHERE booster_opening.opened_at >= :since
            GROUP BY booster.id
            SQL, ['since' => $since]);

        $availableTiers = $this->fetchAvailableTiers(array_values(array_unique(array_map(
            fn (array $row): string => $this->string($row, 'extension_id'),
            $openedBoosters,
        ))));

        $expected = array_fill_keys($this->bucketKeys(), 0.0);
        $expectedHolos = 0.0;
        $expectedCards = 0;
        $openingCount = 0;

        foreach ($openedBoosters as $row) {
            $openings = $this->int($row, 'openings');
            $openingCount += $openings;
            $tiers = $availableTiers[$this->string($row, 'extension_id')] ?? [];

            foreach ($this->decodeSlots($this->string($row, 'rarity_rates')) as $slot) {
                $uniqueShare = min(1.0, max(0.0, $slot['uniqueChance'] / Booster::UNIQUE_CHANCE_SCALE));
                $expected[self::UNIQUE_BUCKET] += $openings * $uniqueShare;
                $weightSum = array_sum($slot['rarities']);

                foreach ($slot['rarities'] as $rarityValue => $weight) {
                    $rolled = CardRarityEnum::tryFrom($rarityValue);

                    if (null === $rolled || $weightSum <= 0) {
                        continue;
                    }

                    $tier = CardDrawer::nearestAvailableRarity($rolled, $tiers) ?? $rolled;
                    $expected[$tier->value] += $openings * (1 - $uniqueShare) * $weight / $weightSum;
                }

                $expectedHolos += $openings * $slot['holoChance'] / 100;
                $expectedCards += $openings;
            }
        }

        $observedCards = array_sum(array_column($observed, 'cards'));
        $observedHolos = array_sum(array_column($observed, 'holos'));

        $rows = [];

        foreach ($this->bucketKeys() as $bucket) {
            $count = $observed[$bucket]['cards'] ?? 0;
            $rows[] = new RarityComparisonRow(
                $bucket,
                self::UNIQUE_BUCKET === $bucket ? 'Unique 1/1' : CardRarityEnum::from($bucket)->label(),
                $count,
                $this->share($count, $observedCards),
                $this->share($expected[$bucket], $expectedCards),
            );
        }

        return new RarityComparison(
            $openingCount,
            $observedCards,
            $rows,
            new RarityComparisonRow('holo', 'Holo', $observedHolos, $this->share($observedHolos, $observedCards), $this->share($expectedHolos, $expectedCards)),
        );
    }

    /**
     * @return array<string, array{cards: int, holos: int}> bucket => drawn copies (quantity already includes the holos)
     */
    private function fetchObservedBuckets(string $since): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT CASE WHEN card.unique_flag THEN :uniqueBucket ELSE card.rarity END AS bucket,
                   SUM(booster_opening_card.quantity) AS cards,
                   SUM(booster_opening_card.holo_quantity) AS holos
            FROM booster_opening_card
            JOIN booster_opening ON booster_opening.id = booster_opening_card.booster_opening_id
            JOIN card ON card.id = booster_opening_card.card_id
            WHERE booster_opening.opened_at >= :since
            GROUP BY bucket
            SQL, ['since' => $since, 'uniqueBucket' => self::UNIQUE_BUCKET]);

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[$this->string($row, 'bucket')] = ['cards' => $this->int($row, 'cards'), 'holos' => $this->int($row, 'holos')];
        }

        return $buckets;
    }

    /**
     * Tiers holding at least one drawable regular card, per extension.
     *
     * @param list<string> $extensionIds
     *
     * @return array<string, array<string, true>>
     */
    private function fetchAvailableTiers(array $extensionIds): array
    {
        if ([] === $extensionIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT extension_id, rarity FROM card WHERE extension_id IN (:extensions) AND status = :published AND unique_flag = false GROUP BY extension_id, rarity',
            ['extensions' => $extensionIds, 'published' => CardStatusEnum::PUBLISHED->value],
            ['extensions' => ArrayParameterType::STRING],
        );

        $tiers = [];

        foreach ($rows as $row) {
            $tiers[$this->string($row, 'extension_id')][$this->string($row, 'rarity')] = true;
        }

        return $tiers;
    }

    /**
     * @return list<array{rarities: array<string, int>, holoChance: int, uniqueChance: int}>
     */
    private function decodeSlots(string $json): array
    {
        $decoded = json_decode($json, true);
        $slots = [];

        foreach (\is_array($decoded) ? $decoded : [] as $slot) {
            if (!\is_array($slot) || !\is_array($slot['rarities'] ?? null)) {
                continue;
            }

            $rarities = [];

            foreach ($slot['rarities'] as $rarity => $weight) {
                $rarities[(string) $rarity] = max(0, (int) $weight);
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
     * @return list<string>
     */
    private function bucketKeys(): array
    {
        return [...array_map(static fn (CardRarityEnum $rarity): string => $rarity->value, CardRarityEnum::ascending()), self::UNIQUE_BUCKET];
    }

    /**
     * UNION ALL of the activity sources since the given placeholder.
     */
    private function activitySql(string $sincePlaceholder): string
    {
        $parts = [];

        foreach (self::ACTIVITY_SOURCES as $table => $column) {
            $parts[] = \sprintf('SELECT discord_user_id AS user_id, %2$s AS happened_at FROM %1$s WHERE %2$s >= %3$s', $table, $column, $sincePlaceholder);
        }

        return implode(' UNION ALL ', $parts);
    }

    private function dayExpression(string $column): string
    {
        return \sprintf("to_char((%s AT TIME ZONE 'UTC') AT TIME ZONE :timezone, 'YYYY-MM-DD')", $column);
    }

    /**
     * @return array<string, int>
     */
    private function fetchDayTotals(string $sql, string $since): array
    {
        $totals = [];

        foreach ($this->connection->fetchAllAssociative($sql, ['timezone' => self::TIMEZONE, 'since' => $since]) as $row) {
            $totals[$this->string($row, 'day')] = $this->int($row, 'total');
        }

        return $totals;
    }

    /**
     * @param list<string>       $days
     * @param array<string, int> $totals
     *
     * @return array<string, int>
     */
    private function fillDays(array $days, array $totals): array
    {
        $filled = [];

        foreach ($days as $day) {
            $filled[$day] = $totals[$day] ?? 0;
        }

        return $filled;
    }

    /**
     * @return list<string>
     */
    private function listDays(\DateTimeImmutable $start): array
    {
        $days = [];
        $today = $this->today();

        for ($day = $start; $day <= $today; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
    }

    /**
     * Timestamps are stored UTC without zone: bind the same instant in UTC.
     */
    private function toUtc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(self::UTC_FORMAT);
    }

    private function share(float | int $part, float | int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 2) : 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
