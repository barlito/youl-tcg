<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Dto\BoosterCodeRedemptionAttempt;
use App\Entity\BoosterCode;
use App\Entity\BoosterCodeRedemption;
use App\Entity\DiscordUser;
use App\Enum\Notification\NotificationTypeEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Exception\Booster\BoosterCodeRefusedException;
use App\Repository\BoosterCodeRepository;
use App\Service\Notification\NotificationService;
use App\Service\Realtime\UserEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Redeems a booster code: credits the UserBooster inventory and leaves a
 * BoosterCodeRedemption audit row. The refusal rules live in the
 * RedeemableBoosterCode constraint — this service only orchestrates.
 *
 * Deliberately independent from BoosterClaimService: a code is a distribution
 * channel of its own, so it neither checks nor consumes the daily quota (which
 * counts BoosterClaim rows), and it works on non-claimable boosters — that is
 * precisely what event packs are for.
 */
final readonly class BoosterCodeRedeemService
{
    public function __construct(
        private BoosterCodeRepository $boosterCodeRepository,
        private UserInventoryService $userInventoryService,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private UserEventPublisher $userEventPublisher,
        private NotificationService $notificationService,
    ) {
    }

    /**
     * @throws BoosterCodeRefusedException
     */
    public function redeem(DiscordUser $discordUser, string $rawCode): BoosterCodeRedemption
    {
        $code = BoosterCodeGenerator::normalize($rawCode);

        $redemption = $this->entityManager->wrapInTransaction(function () use ($discordUser, $code): BoosterCodeRedemption {
            // Resolved AND validated inside the transaction: the row is read
            // with a write lock, so what the constraint checks (uses vs
            // maxUses, one redemption per player) still holds when the
            // increment below runs. Concurrent redemptions serialize here.
            $attempt = new BoosterCodeRedemptionAttempt(
                $code,
                $discordUser,
                '' === $code ? null : $this->boosterCodeRepository->findOneForUpdate($code),
            );

            $violations = $this->validator->validate($attempt);

            if (0 !== \count($violations)) {
                throw BoosterCodeRefusedException::fromViolations($violations);
            }

            $boosterCode = $attempt->boosterCode;
            \assert($boosterCode instanceof BoosterCode); // guaranteed by the validation above

            $this->userInventoryService->creditBooster($discordUser, $boosterCode->getBooster(), $boosterCode->getQuantity());
            $boosterCode->incrementUses();

            $redemption = new BoosterCodeRedemption($boosterCode, $discordUser, $this->clock->now(), $boosterCode->getQuantity());
            $this->entityManager->persist($redemption);
            $this->entityManager->flush();

            return $redemption;
        });

        // post-commit: a rolled back action never reaches the browser
        $this->userEventPublisher->publish($discordUser, UserEventEnum::INVENTORY_CHANGED);
        $this->notificationService->notify($discordUser, NotificationTypeEnum::BOOSTER_CREDITED, [
            'boosterName' => $redemption->getBoosterCode()->getBooster()->getDisplayName(),
            'quantity' => $redemption->getQuantity(),
            'channel' => 'code',
        ], alreadyRead: true);

        return $redemption;
    }
}
