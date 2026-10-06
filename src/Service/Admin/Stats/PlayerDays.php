<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

use App\Service\Admin\EconomyStatsProvider;

/**
 * Distinct Europe/Paris days per player, from one grouped query each.
 */
final readonly class PlayerDays
{
    private const string EPOCH = "TIMESTAMP '1970-01-01'";

    public function __construct(private StatsDb $db, private EconomyStatsProvider $economy)
    {
    }

    /**
     * Days with any ACTIVITY_SOURCES action (the dashboard's definition of active).
     *
     * @return array<string, list<string>>
     */
    public function activity(): array
    {
        return $this->load(\sprintf(
            'SELECT user_id, %s AS day FROM (%s) activity GROUP BY user_id, day',
            $this->db->day('happened_at'),
            $this->economy->activitySql(self::EPOCH),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    public function openings(): array
    {
        return $this->load(\sprintf('SELECT discord_user_id AS user_id, %s AS day FROM booster_opening GROUP BY user_id, day', $this->db->day('opened_at')));
    }

    /**
     * @return array<string, list<string>>
     */
    private function load(string $sql): array
    {
        $days = [];

        foreach ($this->db->all($sql) as $row) {
            $days[StatsFormat::string($row, 'user_id')][] = StatsFormat::string($row, 'day');
        }

        foreach ($days as &$list) {
            sort($list);
        }

        return $days;
    }
}
