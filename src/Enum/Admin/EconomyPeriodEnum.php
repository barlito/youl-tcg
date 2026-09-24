<?php

declare(strict_types=1);

namespace App\Enum\Admin;

/**
 * Chart windows of the admin economy dashboard, in Europe/Paris calendar days
 * (today included).
 */
enum EconomyPeriodEnum: int
{
    case WEEK = 7;
    case MONTH = 30;
    case QUARTER = 90;

    public const self DEFAULT = self::MONTH;

    public function label(): string
    {
        return \sprintf('%d jours', $this->value);
    }

    public static function fromQuery(mixed $value): self
    {
        return is_numeric($value) ? (self::tryFrom((int) $value) ?? self::DEFAULT) : self::DEFAULT;
    }
}
