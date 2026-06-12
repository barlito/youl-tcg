<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\DiscordUser;
use App\Repository\BoosterClaimRepository;
use Psr\Clock\ClockInterface;

/**
 * Production quota: DAILY_LIMIT claims per day, resetting at midnight
 * Europe/Paris. Counts BoosterClaim rows since the last reset — no mutable
 * counter anywhere.
 */
final readonly class DailyBoosterClaimQuota implements BoosterClaimQuotaInterface
{
    private const string TIMEZONE = 'Europe/Paris';

    public function __construct(
        private BoosterClaimRepository $boosterClaimRepository,
        private ClockInterface $clock,
    ) {
    }

    public function getRemainingClaims(DiscordUser $discordUser): int
    {
        $claimsToday = $this->boosterClaimRepository->countSince($discordUser, $this->startOfToday());

        return max(0, self::DAILY_LIMIT - $claimsToday);
    }

    public function getNextResetTime(): \DateTimeImmutable
    {
        return $this->startOfToday()->modify('+1 day');
    }

    /**
     * Midnight Europe/Paris expressed as an absolute point in time, so the
     * comparison with UTC-stored claimed_at timestamps stays correct across
     * DST changes.
     */
    private function startOfToday(): \DateTimeImmutable
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->setTime(0, 0)
        ;
    }
}
