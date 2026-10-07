<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Service\Admin\EconomyStatsProvider;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;

final readonly class TradesSection extends AbstractStatsSection
{
    private const int TOP = 10;

    public function __construct(StatsDb $db, private EconomyStatsProvider $economy)
    {
        parent::__construct($db);
    }

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::TRADES;
    }

    public function build(StatsContext $context): array
    {
        $rows = $this->db->keyed(
            'SELECT status, COUNT(*) AS total, COUNT(*) FILTER (WHERE created_at >= :since) AS period FROM trade_offer GROUP BY status',
            'status',
            ['since' => $context->since],
        );

        $byStatus = [];

        foreach (TradeOfferStatusEnum::cases() as $status) {
            $byStatus[$status->value] = ['total' => StatsFormat::int($rows[$status->value] ?? [], 'total'), 'period' => StatsFormat::int($rows[$status->value] ?? [], 'period')];
        }

        $activity = $this->economy->countTradesPerDay($context->days, $context->since);
        $closed = $this->closedPerDay($context);

        return [
            'offersByStatus' => $byStatus,
            'perDay' => array_map(static fn (string $day): array => [
                'day' => $day,
                'created' => $activity->createdPerDay[$day] ?? 0,
                'accepted' => $activity->acceptedPerDay[$day] ?? 0,
                'refused' => $activity->refusedPerDay[$day] ?? 0,
                'cancelled' => $closed[$day][TradeOfferStatusEnum::CANCELLED->value] ?? 0,
                'invalidated' => $closed[$day][TradeOfferStatusEnum::INVALIDATED->value] ?? 0,
            ], $context->days),
            'acceptanceRatePercent' => [
                'allTime' => $this->acceptanceRate($byStatus, 'total'),
                'period' => $this->acceptanceRate($byStatus, 'period'),
            ],
            'lines' => $this->lines(),
            'mostOfferedCards' => $this->topCards($context, TradeOfferSideEnum::OFFERED),
            'mostRequestedCards' => $this->topCards($context, TradeOfferSideEnum::REQUESTED),
            'mostActivePlayers' => [
                'proposers' => $this->topPlayers($context, 'proposer_id'),
                'receivers' => $this->topPlayers($context, 'receiver_id'),
            ],
        ];
    }

    /**
     * @return array<string, array<string, int>> day => status => offers closed that day
     */
    private function closedPerDay(StatsContext $context): array
    {
        $rows = $this->db->all(
            \sprintf('SELECT %s AS day, status, COUNT(*) AS total FROM trade_offer WHERE status IN (:cancelled, :invalidated) AND resolved_at >= :since GROUP BY day, status', $this->db->day('resolved_at')),
            ['since' => $context->since, 'cancelled' => TradeOfferStatusEnum::CANCELLED->value, 'invalidated' => TradeOfferStatusEnum::INVALIDATED->value],
        );

        $closed = [];

        foreach ($rows as $row) {
            $closed[StatsFormat::string($row, 'day')][StatsFormat::string($row, 'status')] = StatsFormat::int($row, 'total');
        }

        return $closed;
    }

    /**
     * Accepted / (accepted + refused), like the dashboard.
     *
     * @param array<string, array{total: int, period: int}> $byStatus
     */
    private function acceptanceRate(array $byStatus, string $scope): float
    {
        $accepted = $byStatus[TradeOfferStatusEnum::ACCEPTED->value][$scope];

        return StatsFormat::share($accepted, $accepted + $byStatus[TradeOfferStatusEnum::REFUSED->value][$scope]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lines(): array
    {
        $offers = StatsFormat::int($this->db->one('SELECT COUNT(*) AS total FROM trade_offer'), 'total');
        $perSide = $this->db->keyed(
            'SELECT side, COUNT(*) AS lines, COALESCE(SUM(normal_quantity + holo_quantity), 0) AS copies, COUNT(DISTINCT trade_offer_id) AS offers FROM trade_offer_line GROUP BY side',
            'side',
        );

        $result = ['offers' => $offers];
        $allLines = 0;

        foreach (TradeOfferSideEnum::cases() as $side) {
            $row = $perSide[$side->value] ?? [];
            $lines = StatsFormat::int($row, 'lines');
            $allLines += $lines;
            $result[$side->value] = [
                'lines' => $lines,
                'copies' => StatsFormat::int($row, 'copies'),
                'averageLinesPerOffer' => $offers > 0 ? round($lines / $offers, 2) : null,
                'averageCopiesPerOffer' => $offers > 0 ? round(StatsFormat::int($row, 'copies') / $offers, 2) : null,
            ];
        }

        $result['averageLinesPerOffer'] = $offers > 0 ? round($allLines / $offers, 2) : null;

        return $result;
    }

    /**
     * @return array{allTime: list<array<string, mixed>>, period: list<array<string, mixed>>}
     */
    private function topCards(StatsContext $context, TradeOfferSideEnum $side): array
    {
        $result = [];

        foreach (['allTime' => '', 'period' => ' AND o.created_at >= :since'] as $scope => $filter) {
            $rows = $this->db->all(
                \sprintf(
                    'SELECT c.id, c.name, c.rarity, COUNT(DISTINCT o.id) AS offers, SUM(l.normal_quantity + l.holo_quantity) AS copies FROM trade_offer_line l JOIN trade_offer o ON o.id = l.trade_offer_id JOIN card c ON c.id = l.card_id WHERE l.side = :side%s GROUP BY c.id, c.name, c.rarity ORDER BY offers DESC, copies DESC, c.name, c.id LIMIT %d',
                    $filter,
                    self::TOP,
                ),
                $this->parameters($context, ['side' => $side->value], $filter),
            );

            $result[$scope] = array_map(static fn (array $row): array => [
                'card' => ['id' => StatsFormat::string($row, 'id'), 'name' => StatsFormat::string($row, 'name'), 'rarity' => StatsFormat::string($row, 'rarity')],
                'offers' => StatsFormat::int($row, 'offers'),
                'copies' => StatsFormat::int($row, 'copies'),
            ], $rows);
        }

        return $result;
    }

    /**
     * @return array{allTime: list<array<string, mixed>>, period: list<array<string, mixed>>}
     */
    private function topPlayers(StatsContext $context, string $column): array
    {
        $result = [];

        foreach (['allTime' => '', 'period' => ' WHERE o.created_at >= :since'] as $scope => $filter) {
            $rows = $this->db->all(
                \sprintf(
                    'SELECT u.discord_id, u.username, COUNT(*) AS offers, COUNT(*) FILTER (WHERE o.status = :accepted) AS accepted FROM trade_offer o JOIN discord_user u ON u.discord_id = o.%s%s GROUP BY u.discord_id, u.username ORDER BY offers DESC, u.username, u.discord_id LIMIT %d',
                    $column,
                    $filter,
                    self::TOP,
                ),
                $this->parameters($context, ['accepted' => TradeOfferStatusEnum::ACCEPTED->value], $filter),
            );

            $result[$scope] = array_map(fn (array $row): array => [
                'player' => $this->player(StatsFormat::string($row, 'discord_id'), StatsFormat::string($row, 'username')),
                'offers' => StatsFormat::int($row, 'offers'),
                'accepted' => StatsFormat::int($row, 'accepted'),
            ], $rows);
        }

        return $result;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, string>
     */
    private function parameters(StatsContext $context, array $parameters, string $filter): array
    {
        return '' === $filter ? $parameters : [...$parameters, 'since' => $context->since];
    }
}
