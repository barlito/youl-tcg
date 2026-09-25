<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Entity\BoosterClaim;
use App\Entity\DiscordUser;
use App\Enum\Realtime\UserEventEnum;
use App\Exception\Booster\BoosterNotClaimableException;
use App\Exception\Booster\DailyClaimLimitReachedException;
use App\Service\Realtime\UserEventPublisher;
use Doctrine\DBAL\LockMode;
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
        private BoosterAvailabilityService $boosterAvailability,
        private UserEventPublisher $userEventPublisher,
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
     * @throws BoosterNotClaimableException
     */
    public function claim(DiscordUser $discordUser, Booster $booster): BoosterClaim
    {
        // Server-side guard, not just UI: a forged live action must not claim
        // an event/code-only booster, nor one that cannot be opened.
        if (!$this->boosterAvailability->isRetrievable($booster)) {
            throw new BoosterNotClaimableException(
                \sprintf('Booster "%s" is not retrievable (not claimable, unpublished extension or nothing to draw).', $booster->getDisplayName()),
                'Ce pack n\'est pas récupérable pour le moment.',
            );
        }

        $claim = $this->entityManager->wrapInTransaction(function () use ($discordUser, $booster): BoosterClaim {
            // The quota is a COUNT then an INSERT (check-then-act): lock the
            // user row so concurrent claims of the same user serialize instead
            // of both passing the check and overshooting the daily limit.
            $this->entityManager->find(DiscordUser::class, $discordUser->getDiscordId(), LockMode::PESSIMISTIC_WRITE);

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
        });

        // post-commit: a rolled back action never reaches the browser
        $this->userEventPublisher->publish($discordUser, UserEventEnum::INVENTORY_CHANGED);

        return $claim;
    }
}
