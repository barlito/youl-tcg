<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsFormat;
use App\Service\Wishlist\WishlistService;

final readonly class WishlistSection extends AbstractStatsSection
{
    private const int TOP = 10;

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::WISHLIST;
    }

    public function build(StatsContext $context): array
    {
        $params = ['since' => $context->since];
        $wishes = $this->db->one('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE created_at >= :since) AS period, COUNT(DISTINCT player_id) AS players, COUNT(DISTINCT card_id) AS cards FROM wishlist_entry', $params);
        $watches = $this->db->one('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE created_at >= :since) AS period, COUNT(DISTINCT player_id) AS players FROM wishlist_universe', $params);
        $alerts = $this->db->one('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE created_at >= :since) AS period, COUNT(DISTINCT player_id) AS players, COUNT(DISTINCT listing_id) AS listings FROM wishlist_alert', $params);
        $engaged = $this->db->one('SELECT COUNT(*) AS total FROM (SELECT player_id FROM wishlist_entry UNION SELECT player_id FROM wishlist_universe) engaged');

        $alertsPerDay = [];
        foreach ($this->db->all(\sprintf('SELECT %s AS day, COUNT(*) AS alerts FROM wishlist_alert WHERE created_at >= :since GROUP BY day', $this->db->day('created_at')), $params) as $row) {
            $alertsPerDay[StatsFormat::string($row, 'day')] = $row;
        }

        return [
            'maxEntriesPerPlayer' => WishlistService::MAX_ENTRIES,
            'playersEngaged' => StatsFormat::int($engaged, 'total'),
            'wishes' => [
                'total' => StatsFormat::int($wishes, 'total'),
                'period' => StatsFormat::int($wishes, 'period'),
                'players' => StatsFormat::int($wishes, 'players'),
                'distinctCards' => StatsFormat::int($wishes, 'cards'),
            ],
            'universeWatches' => [
                'total' => StatsFormat::int($watches, 'total'),
                'period' => StatsFormat::int($watches, 'period'),
                'players' => StatsFormat::int($watches, 'players'),
            ],
            'alertsSent' => [
                'total' => StatsFormat::int($alerts, 'total'),
                'period' => StatsFormat::int($alerts, 'period'),
                'players' => StatsFormat::int($alerts, 'players'),
                'listings' => StatsFormat::int($alerts, 'listings'),
            ],
            'alertsPerDay' => $this->series($context, $alertsPerDay, ['alerts']),
            'mostWishedCards' => $this->mostWishedCards(),
            'watchedUniverses' => $this->watchedUniverses(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mostWishedCards(): array
    {
        $rows = $this->db->all(
            \sprintf('SELECT c.id, c.name, c.rarity, COUNT(*) AS wishes FROM wishlist_entry w JOIN card c ON c.id = w.card_id GROUP BY c.id, c.name, c.rarity ORDER BY wishes DESC, c.name, c.id LIMIT %d', self::TOP),
        );

        return array_map(static fn (array $row): array => [
            'card' => ['id' => StatsFormat::string($row, 'id'), 'name' => StatsFormat::string($row, 'name'), 'rarity' => StatsFormat::string($row, 'rarity')],
            'wishes' => StatsFormat::int($row, 'wishes'),
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function watchedUniverses(): array
    {
        $rows = $this->db->all('SELECT e.slug, e.name, COUNT(u.id) AS watchers FROM extension e JOIN wishlist_universe u ON u.extension_id = e.id GROUP BY e.id, e.slug, e.name ORDER BY watchers DESC, e.name, e.id');

        return array_map(static fn (array $row): array => [
            'extension' => StatsFormat::string($row, 'slug'),
            'name' => StatsFormat::string($row, 'name'),
            'watchers' => StatsFormat::int($row, 'watchers'),
        ], $rows);
    }
}
