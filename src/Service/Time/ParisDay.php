<?php

declare(strict_types=1);

namespace App\Service\Time;

use Psr\Clock\ClockInterface;

/**
 * The Europe/Paris calendar day that daily quotas run on, as absolute points in time.
 */
final readonly class ParisDay
{
    private const string TIMEZONE = 'Europe/Paris';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function start(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
    }

    public function end(): \DateTimeImmutable
    {
        return $this->start()->modify('+1 day');
    }

    public function secondsUntilEnd(): int
    {
        return max(0, $this->end()->getTimestamp() - $this->clock->now()->getTimestamp());
    }
}
