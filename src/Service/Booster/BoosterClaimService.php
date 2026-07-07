<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Entity\BoosterClaim;
use App\Entity\DiscordUser;
use App\Exception\Booster\DailyClaimLimitReachedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Daily free booster claims: the quota policy (BoosterClaimQuotaInterface)
 * decides how many claims are left; a claim credits the UserBooster
 * inventory and leaves a BoosterClaim audit row.
 */
final readonly class BoosterClaimService
{
    public function __construct(
        private BoosterClaimQuotaInterface $quota,
        private UserInventoryService $userInventoryService,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function getRemainingClaims(DiscordUser $discordUser): int
    {
        return $this->quota->getRemainingClaims($discordUser);
    }

    public function getNextResetTime(): \DateTimeImmutable
    {
        return $this->quota->getNextResetTime();
    }

    /**
     * @throws DailyClaimLimitReachedException
     */
    public function claim(DiscordUser $discordUser, Booster $booster): BoosterClaim
    {
        if ($this->quota->getRemainingClaims($discordUser) < 1) {
            throw new DailyClaimLimitReachedException(
                \sprintf('Daily limit of %d boosters reached, come back tomorrow.', BoosterClaimQuotaInterface::DAILY_LIMIT),
                \sprintf('Limite quotidienne de %d boosters atteinte, reviens demain !', BoosterClaimQuotaInterface::DAILY_LIMIT),
            );
        }

        $this->userInventoryService->creditBooster($discordUser, $booster);

        $claim = new BoosterClaim($discordUser, $booster, $this->clock->now());
        $this->entityManager->persist($claim);
        $this->entityManager->flush();

        return $claim;
    }
}
