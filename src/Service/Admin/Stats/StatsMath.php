<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

final class StatsMath
{
    /**
     * @param list<float|int> $values
     */
    public static function median(array $values): ?float
    {
        if ([] === $values) {
            return null;
        }

        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);

        return 1 === $count % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param list<float|int> $values
     */
    public static function average(array $values): ?float
    {
        return [] === $values ? null : round(array_sum($values) / \count($values), 2);
    }

    /**
     * @param list<float|int> $values
     *
     * @return array{count: int, min: float|int|null, median: float|null, max: float|int|null, average: float|null}
     */
    public static function summary(array $values): array
    {
        return [
            'count' => \count($values),
            'min' => [] === $values ? null : min($values),
            'median' => self::median($values),
            'max' => [] === $values ? null : max($values),
            'average' => self::average($values),
        ];
    }
}
