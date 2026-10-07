<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Dto\Admin\RarityComparisonRow;
use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\EconomyStatsProvider;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;

final readonly class DrawsSection extends AbstractStatsSection
{
    public function __construct(StatsDb $db, private EconomyStatsProvider $economy)
    {
        parent::__construct($db);
    }

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::DRAWS;
    }

    public function build(StatsContext $context): array
    {
        $comparison = $this->economy->compareRarities($context->since);

        return [
            'rarities' => [
                'openings' => $comparison->openingCount,
                'cards' => $comparison->cardCount,
                'buckets' => array_map($this->row(...), $comparison->rows),
                'holo' => $this->row($comparison->holo),
            ],
            'perExtension' => $this->perExtension($context),
            'holoPerBooster' => $this->holoPerBooster($context),
            'uniquesDrawn' => $this->uniquesDrawn(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RarityComparisonRow $row): array
    {
        return [
            'key' => $row->key,
            'label' => $row->label,
            'observedCount' => $row->observedCount,
            'observedSharePercent' => $row->observedShare,
            'expectedSharePercent' => $row->expectedShare,
            'gapPoints' => $row->gap(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function perExtension(StatsContext $context): array
    {
        $openings = $this->db->keyed(
            'SELECT e.slug, COUNT(*) AS total FROM booster_opening o JOIN booster b ON b.id = o.booster_id JOIN extension e ON e.id = b.extension_id WHERE o.opened_at >= :since GROUP BY e.slug',
            'slug',
            ['since' => $context->since],
        );

        $rows = $this->db->all(<<<'SQL'
            SELECT e.slug, e.name, CASE WHEN c.unique_flag THEN :unique ELSE c.rarity END AS bucket,
                   SUM(oc.quantity) AS cards, SUM(oc.holo_quantity) AS holos
            FROM booster_opening_card oc
            JOIN booster_opening o ON o.id = oc.booster_opening_id
            JOIN card c ON c.id = oc.card_id
            JOIN extension e ON e.id = c.extension_id
            WHERE o.opened_at >= :since
            GROUP BY e.slug, e.name, bucket
            ORDER BY e.name, e.slug
            SQL, ['since' => $context->since, 'unique' => EconomyStatsProvider::UNIQUE_BUCKET]);

        $perExtension = [];

        foreach ($rows as $row) {
            $slug = StatsFormat::string($row, 'slug');
            $perExtension[$slug]['extension'] = $slug;
            $perExtension[$slug]['name'] = StatsFormat::string($row, 'name');
            $perExtension[$slug]['cards'] = ($perExtension[$slug]['cards'] ?? 0) + StatsFormat::int($row, 'cards');
            $perExtension[$slug]['holos'] = ($perExtension[$slug]['holos'] ?? 0) + StatsFormat::int($row, 'holos');
            $perExtension[$slug]['byBucket'][StatsFormat::string($row, 'bucket')] = StatsFormat::int($row, 'cards');
        }

        $result = [];

        foreach ($perExtension as $slug => $entry) {
            $result[] = ['openings' => StatsFormat::int($openings[$slug] ?? [], 'total'), ...$entry];
        }

        return $result;
    }

    /**
     * Expected = average holoChance over the slots; the second observed rate leaves alwaysHolo cards out.
     *
     * @return list<array<string, mixed>>
     */
    private function holoPerBooster(StatsContext $context): array
    {
        $observed = $this->db->keyed(<<<'SQL'
            SELECT o.booster_id, COUNT(DISTINCT o.id) AS openings, SUM(oc.quantity) AS cards, SUM(oc.holo_quantity) AS holos,
                   COALESCE(SUM(oc.quantity) FILTER (WHERE NOT c.always_holo), 0) AS regular_cards,
                   COALESCE(SUM(oc.holo_quantity) FILTER (WHERE NOT c.always_holo), 0) AS regular_holos
            FROM booster_opening o
            JOIN booster_opening_card oc ON oc.booster_opening_id = o.id
            JOIN card c ON c.id = oc.card_id
            WHERE o.opened_at >= :since
            GROUP BY o.booster_id
            SQL, 'booster_id', ['since' => $context->since]);

        $result = [];

        foreach ($this->db->all('SELECT b.id, COALESCE(b.name, e.name) AS display_name, b.rarity_rates FROM booster b JOIN extension e ON e.id = b.extension_id ORDER BY e.name, display_name, b.id') as $row) {
            $id = StatsFormat::string($row, 'id');
            $slots = StatsFormat::json($row, 'rarity_rates');
            $chances = array_map(static fn (mixed $slot): int => \is_array($slot) ? (int) ($slot['holoChance'] ?? 0) : 0, $slots);
            $seen = $observed[$id] ?? [];

            $result[] = [
                'booster' => ['id' => $id, 'displayName' => StatsFormat::string($row, 'display_name')],
                'openings' => StatsFormat::int($seen, 'openings'),
                'cards' => StatsFormat::int($seen, 'cards'),
                'holos' => StatsFormat::int($seen, 'holos'),
                'observedHoloRatePercent' => StatsFormat::share(StatsFormat::int($seen, 'holos'), StatsFormat::int($seen, 'cards')),
                'observedHoloRateExcludingAlwaysHoloPercent' => StatsFormat::share(StatsFormat::int($seen, 'regular_holos'), StatsFormat::int($seen, 'regular_cards')),
                'configuredHoloRatePercent' => [] === $chances ? null : round(array_sum($chances) / \count($chances), 2),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function uniquesDrawn(): array
    {
        $rows = $this->db->all(<<<'SQL'
            SELECT c.id, c.name, e.slug AS extension, holder.discord_id AS holder_id, holder.username AS holder_name,
                   first_draw.opened_at AS first_drawn_at, drawer.discord_id AS drawer_id, drawer.username AS drawer_name
            FROM card c
            JOIN extension e ON e.id = c.extension_id
            JOIN discord_user holder ON holder.discord_id = c.claimed_by
            LEFT JOIN LATERAL (
                SELECT o.opened_at, o.discord_user_id
                FROM booster_opening_card oc
                JOIN booster_opening o ON o.id = oc.booster_opening_id
                WHERE oc.card_id = c.id
                ORDER BY o.opened_at ASC
                LIMIT 1
            ) first_draw ON TRUE
            LEFT JOIN discord_user drawer ON drawer.discord_id = first_draw.discord_user_id
            WHERE c.unique_flag
            ORDER BY first_draw.opened_at, c.name, c.id
            SQL);

        return array_map(fn (array $row): array => [
            'card' => ['id' => StatsFormat::string($row, 'id'), 'name' => StatsFormat::string($row, 'name'), 'extension' => StatsFormat::string($row, 'extension')],
            'currentHolder' => $this->player(StatsFormat::string($row, 'holder_id'), StatsFormat::string($row, 'holder_name')),
            'drawnBy' => $this->player(StatsFormat::nullableString($row, 'drawer_id'), StatsFormat::nullableString($row, 'drawer_name')),
            'drawnAt' => StatsFormat::iso($row, 'first_drawn_at'),
        ], $rows);
    }
}
