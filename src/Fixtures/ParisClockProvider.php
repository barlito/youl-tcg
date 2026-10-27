<?php

declare(strict_types=1);

namespace App\Fixtures;

use Faker\Provider\Base;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Dev fixtures dates: "N Paris days ago at HH:MM", converted to UTC (Doctrine
 * binds datetimes without converting them) and never later than now, so the
 * scenarios (streak, daily quotas, "today") stay true whenever they are loaded.
 */
#[When(env: 'dev')]
final class ParisClockProvider extends Base
{
    private const string PARIS = 'Europe/Paris';

    public function parisAt(int $daysAgo, string $time = '12:00'): \DateTimeImmutable
    {
        $paris = new \DateTimeZone(self::PARIS);
        $now = new \DateTimeImmutable('now', $paris);
        $at = $now->setTime(0, 0)->modify(\sprintf('-%d days', $daysAgo))->modify($time);
        $latest = $now->modify('-1 minute');

        return ($at > $latest ? $latest : $at)->setTimezone(new \DateTimeZone('UTC'));
    }

    public function parisAtMutable(int $daysAgo, string $time = '12:00'): \DateTime
    {
        return \DateTime::createFromImmutable($this->parisAt($daysAgo, $time));
    }

    /**
     * A Paris calendar day for DATE columns (streak series identity).
     */
    public function parisDay(int $daysAgo): \DateTimeImmutable
    {
        $day = new \DateTimeImmutable('now', new \DateTimeZone(self::PARIS))->modify(\sprintf('-%d days', $daysAgo));

        return new \DateTimeImmutable($day->format('Y-m-d'));
    }

    public function hoursAgo(int $hours): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->modify(\sprintf('-%d hours', $hours));
    }

    public function hoursAgoMutable(int $hours): \DateTime
    {
        return \DateTime::createFromImmutable($this->hoursAgo($hours));
    }
}
