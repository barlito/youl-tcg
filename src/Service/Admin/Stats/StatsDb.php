<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

use App\Service\Admin\EconomyStatsProvider;
use Doctrine\DBAL\Connection;

final readonly class StatsDb
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = [], array $types = []): array
    {
        if (1 === preg_match('/:timezone\b/', $sql)) {
            $params['timezone'] = EconomyStatsProvider::TIMEZONE;
        }

        return array_values($this->connection->fetchAllAssociative($sql, $params, $types));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function one(string $sql, array $params = []): array
    {
        return $this->all($sql, $params)[0] ?? [];
    }

    /**
     * Rows keyed by one of their string columns (last one wins).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, array<string, mixed>>
     */
    public function keyed(string $sql, string $keyColumn, array $params = []): array
    {
        $rows = [];

        foreach ($this->all($sql, $params) as $row) {
            $rows[StatsFormat::string($row, $keyColumn)] = $row;
        }

        return $rows;
    }

    public function day(string $column): string
    {
        return \sprintf("to_char((%s AT TIME ZONE 'UTC') AT TIME ZONE :timezone, 'YYYY-MM-DD')", $column);
    }
}
