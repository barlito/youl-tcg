<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Entity\DiscordUser;
use App\Entity\StreakReward;
use App\Enum\Realtime\UserEventEnum;
use App\Exception\Booster\BoosterNotClaimableException;
use App\Exception\Booster\StreakRewardUnavailableException;
use App\Repository\StreakRewardRepository;
use App\Service\Realtime\UserEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Streak milestone rewards: granting is an idempotent insert derived from the
 * computed streak (no counter to corrupt), spending credits one claimable
 * booster to the inventory. Like code redemptions, this is its own
 * distribution channel: no BoosterClaim, so the daily quota is untouched.
 */
final readonly class StreakRewardService
{
    public function __construct(
        private OpeningStreakService $openingStreakService,
        private StreakRewardRepository $streakRewardRepository,
        private BoosterAvailabilityService $boosterAvailability,
        private UserInventoryService $userInventoryService,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private UserEventPublisher $userEventPublisher,
    ) {
    }

    /**
     * @return list<StreakReward>
     */
    public function getPendingRewards(DiscordUser $discordUser): array
    {
        return $this->streakRewardRepository->findPending($discordUser);
    }

    /**
     * Grants every milestone the running series has reached. Inserting ALL
     * reached milestones (not just the newest) self-heals a grant that was
     * missed earlier in the same series; the unique index makes each insert
     * idempotent, concurrency included.
     */
    public function grantMilestones(DiscordUser $discordUser): void
    {
        $streak = $this->openingStreakService->getStreak($discordUser);

        if (null === $streak->seriesStartedOn) {
            return;
        }

        foreach ($streak->reachedMilestones() as $milestone) {
            $this->streakRewardRepository->insertIgnore($discordUser, $streak->seriesStartedOn, $milestone, $this->clock->now());
        }
    }

    /**
     * Spends a pending reward on the chosen booster. Same check as a daily
     * claim (BoosterAvailabilityService::isRetrievable): a forged live action
     * must not reach an event/code-only or undrawable pack.
     *
     * @throws StreakRewardUnavailableException
     * @throws BoosterNotClaimableException
     */
    public function chooseBooster(DiscordUser $discordUser, string $rewardId, Booster $booster): StreakReward
    {
        $reward = $this->entityManager->wrapInTransaction(function () use ($discordUser, $rewardId, $booster): StreakReward {
            $reward = $this->streakRewardRepository->findOneForUpdate($rewardId, $discordUser);

            if (!$reward instanceof StreakReward || $reward->isChosen()) {
                throw new StreakRewardUnavailableException(
                    \sprintf('Streak reward "%s" is unknown, already spent, or not owned by "%s".', $rewardId, $discordUser->getDiscordId()),
                    'Cette récompense n\'est plus disponible.',
                );
            }

            if (!$this->boosterAvailability->isRetrievable($booster)) {
                throw new BoosterNotClaimableException(
                    \sprintf('Booster "%s" is not retrievable (not claimable, unpublished extension or nothing to draw).', $booster->getDisplayName()),
                    'Ce pack ne peut pas être choisi en récompense pour le moment.',
                );
            }

            $this->userInventoryService->creditBooster($discordUser, $booster);
            $reward->choose($booster, $this->clock->now());
            $this->entityManager->flush();

            return $reward;
        });

        $this->userEventPublisher->publish($discordUser, UserEventEnum::INVENTORY_CHANGED);

        return $reward;
    }
}
