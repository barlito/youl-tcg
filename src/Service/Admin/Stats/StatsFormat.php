<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

use App\Service\Coin\CoinAmount;

final class StatsFormat
{
    private const string ISO = 'Y-m-d\TH:i:s\Z';

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function float(array $row, string $key): float
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableFloat(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $key): bool
    {
        $value = $row[$key] ?? false;

        return true === $value || 't' === $value || '1' === $value || 1 === $value;
    }

    /**
     * Stored timestamps are UTC without a zone.
     *
     * @param array<string, mixed> $row
     */
    public static function iso(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && '' !== $value ? self::isoFromString($value) : null;
    }

    public static function isoFromString(string $value): string
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'))->format(self::ISO);
    }

    public static function isoNow(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<mixed>
     */
    public static function json(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $decoded = \is_string($value) ? json_decode($value, true) : $value;

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<mixed>
     */
    public static function jsonList(array $row, string $key): array
    {
        return array_values(self::json($row, $key));
    }

    /**
     * A JSON object column: an empty one must stay `{}` once encoded.
     *
     * @param array<string, mixed> $row
     *
     * @return array<mixed>|\stdClass
     */
    public static function jsonObject(array $row, string $key): array | \stdClass
    {
        $decoded = self::json($row, $key);

        return [] === $decoded ? new \stdClass() : $decoded;
    }

    /**
     * @return array{minor: string, coins: int}
     */
    public static function coins(int $coins): array
    {
        return self::minor((string) ($coins * 10 ** CoinAmount::SCALE));
    }

    /**
     * Whole coins are the integer part (truncated), minor units stay exact.
     *
     * @return array{minor: string, coins: int}
     */
    public static function minor(int | string $minor): array
    {
        $amount = CoinAmount::fromMinor((string) $minor)->minor;
        $negative = str_starts_with($amount, '-');
        $digits = str_pad(ltrim($amount, '-'), CoinAmount::SCALE + 1, '0', \STR_PAD_LEFT);
        $whole = (int) substr($digits, 0, -CoinAmount::SCALE);

        return ['minor' => $amount, 'coins' => $negative ? -$whole : $whole];
    }

    public static function share(float | int $part, float | int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 2) : 0.0;
    }
}
