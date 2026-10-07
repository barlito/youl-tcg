<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\EconomyStatsProvider;
use App\Service\Admin\Stats\PlayerDays;
use App\Service\Admin\Stats\RetentionCalculator;
use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;

final readonly class ActivitySection extends AbstractStatsSection
{
    public function __construct(StatsDb $db, private EconomyStatsProvider $economy, private PlayerDays $playerDays)
    {
        parent::__construct($db);
    }

    public function section(): StatsSectionEnum
    {
        return StatsSectionEnum::ACTIVITY;
    }

    public function build(StatsContext $context): array
    {
        $daysByPlayer = $this->playerDays->activity();
        $today = $context->today;
        $dau = $this->activeSince($daysByPlayer, $today);
        $wau = $this->activeSince($daysByPlayer, RetentionCalculator::addDays($today, -6));
        $mau = $this->activeSince($daysByPlayer, RetentionCalculator::addDays($today, -29));

        $newPerDay = [];
        foreach ($daysByPlayer as $days) {
            $first = $days[0] ?? null;
            if (null !== $first) {
                $newPerDay[$first] = ($newPerDay[$first] ?? 0) + 1;
            }
        }

        $registrations = [];
        foreach ($this->db->all(\sprintf('SELECT %s AS day, COUNT(*) AS total FROM discord_user GROUP BY day', $this->db->day('created_at'))) as $row) {
            $registrations[StatsFormat::string($row, 'day')] = StatsFormat::int($row, 'total');
        }

        return [
            'activePlayersPerDay' => $this->dayValues($context, $this->economy->fillDays($context->days, $this->economy->countActivePlayersPerDay($context->since))),
            'openingsPerDay' => $this->dayValues($context, $this->economy->fillDays($context->days, $this->economy->countOpeningsPerDay($context->since))),
            'current' => [
                'dau' => $dau,
                'wau' => $wau,
                'mau' => $mau,
                'dauOverMauPercent' => StatsFormat::share($dau, $mau),
                'registeredPlayers' => StatsFormat::int($this->db->one('SELECT COUNT(*) AS total FROM discord_user'), 'total'),
            ],
            'newPlayersPerDay' => $this->dayValues($context, $newPerDay),
            'registrationsPerDay' => $this->dayValues($context, $registrations),
            'retentionCohorts' => RetentionCalculator::cohorts($daysByPlayer, $today, RetentionCalculator::mondayOf($context->days[0])),
        ];
    }

    /**
     * @param array<string, list<string>> $daysByPlayer
     */
    private function activeSince(array $daysByPlayer, string $day): int
    {
        $count = 0;

        foreach ($daysByPlayer as $days) {
            if ([] !== $days && max($days) >= $day) {
                ++$count;
            }
        }

        return $count;
    }
}
