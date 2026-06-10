<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Entity\BoosterClaim;
use App\Entity\DiscordUser;
use App\Exception\Booster\DailyClaimLimitReachedException;
use App\Repository\BoosterClaimRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Daily free booster claims: every player can claim DAILY_LIMIT boosters per
 * day, the quota resets at midnight Europe/Paris. Claimed boosters land in
 * the UserBooster inventory and can be opened later.
 *
 * The limit can be switched off via BOOSTER_DAILY_LIMIT_ENABLED=false
 * (dev convenience to test openings repeatedly).
 */
final readonly class BoosterClaimService
{
    public const int DAILY_LIMIT = 2;

    private const string TIMEZONE = 'Europe/Paris';

    public function __construct(
        private BoosterClaimRepository $boosterClaimRepository,
        private UserInventoryService $userInventoryService,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        #[Autowire(env: 'bool:BOOSTER_DAILY_LIMIT_ENABLED')]
        private bool $dailyLimitEnabled = true,
    ) {
    }

    public function getRemainingClaims(DiscordUser $discordUser): int
    {
        if (!$this->dailyLimitEnabled) {
            return self::DAILY_LIMIT;
        }

        $claimsToday = $this->boosterClaimRepository->countSince($discordUser, $this->startOfToday());

        return max(0, self::DAILY_LIMIT - $claimsToday);
    }

    /**
     * @throws DailyClaimLimitReachedException
     */
    public function claim(DiscordUser $discordUser, Booster $booster): BoosterClaim
    {
        if ($this->getRemainingClaims($discordUser) < 1) {
            throw new DailyClaimLimitReachedException(\sprintf('Daily limit of %d boosters reached, come back tomorrow.', self::DAILY_LIMIT));
        }

        $this->userInventoryService->creditBooster($discordUser, $booster);

        $claim = new BoosterClaim($discordUser, $booster, $this->clock->now());
        $this->entityManager->persist($claim);
        $this->entityManager->flush();

        return $claim;
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
