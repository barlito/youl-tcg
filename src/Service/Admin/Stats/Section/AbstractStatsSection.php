<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats\Section;

use App\Service\Admin\Stats\StatsContext;
use App\Service\Admin\Stats\StatsDb;
use App\Service\Admin\Stats\StatsFormat;
use App\Service\Admin\Stats\StatsSectionInterface;

abstract readonly class AbstractStatsSection implements StatsSectionInterface
{
    public function __construct(protected StatsDb $db)
    {
    }

    /**
     * One entry per day of the period, zero-filled: [{day, ...values}].
     *
     * @param array<string, array<string, mixed>> $rowsByDay
     * @param list<string>                        $fields    integer columns copied from each row
     *
     * @return list<array<string, mixed>>
     */
    protected function series(StatsContext $context, array $rowsByDay, array $fields): array
    {
        $series = [];

        foreach ($context->days as $day) {
            $entry = ['day' => $day];

            foreach ($fields as $field) {
                $entry[$field] = StatsFormat::int($rowsByDay[$day] ?? [], $field);
            }

            $series[] = $entry;
        }

        return $series;
    }

    /**
     * @param array<string, int> $perDay day => value
     *
     * @return list<array{day: string, value: int}>
     */
    protected function dayValues(StatsContext $context, array $perDay): array
    {
        return array_map(static fn (string $day): array => ['day' => $day, 'value' => $perDay[$day] ?? 0], $context->days);
    }

    /**
     * @return array{discordId: string, username: string|null}|null
     */
    protected function player(?string $discordId, ?string $username): ?array
    {
        return null === $discordId ? null : ['discordId' => $discordId, 'username' => $username];
    }
}
