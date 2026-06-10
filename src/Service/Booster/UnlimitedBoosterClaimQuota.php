<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\DiscordUser;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Dev-only decorator: lifts the daily claim limit so boosters can be opened
 * repeatedly while testing. Never registered outside the dev environment;
 * set BOOSTER_DAILY_LIMIT_ENABLED=true (.env.dev) to restore the real quota.
 */
#[When(env: 'dev')]
#[AsDecorator(decorates: DailyBoosterClaimQuota::class)]
final readonly class UnlimitedBoosterClaimQuota implements BoosterClaimQuotaInterface
{
    public function __construct(
        private BoosterClaimQuotaInterface $inner,
        #[Autowire(env: 'bool:BOOSTER_DAILY_LIMIT_ENABLED')]
        private bool $dailyLimitEnabled,
    ) {
    }

    public function getRemainingClaims(DiscordUser $discordUser): int
    {
        if ($this->dailyLimitEnabled) {
            return $this->inner->getRemainingClaims($discordUser);
        }

        return self::DAILY_LIMIT;
    }

    public function getNextResetTime(): \DateTimeImmutable
    {
        return $this->inner->getNextResetTime();
    }
}
