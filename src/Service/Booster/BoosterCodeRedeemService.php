<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\BoosterCode;
use App\Entity\BoosterCodeRedemption;
use App\Entity\DiscordUser;
use App\Exception\Booster\BoosterCodeAlreadyRedeemedException;
use App\Exception\Booster\BoosterCodeExhaustedException;
use App\Exception\Booster\BoosterCodeExpiredException;
use App\Exception\Booster\BoosterCodeNotAvailableYetException;
use App\Exception\Booster\InvalidBoosterCodeException;
use App\Repository\BoosterCodeRedemptionRepository;
use App\Repository\BoosterCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Redeems a booster code: credits the UserBooster inventory and leaves a
 * BoosterCodeRedemption audit row.
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
        private BoosterCodeRedemptionRepository $boosterCodeRedemptionRepository,
        private UserInventoryService $userInventoryService,
        private BoosterAvailabilityService $boosterAvailability,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidBoosterCodeException
     * @throws BoosterCodeExpiredException
     * @throws BoosterCodeExhaustedException
     * @throws BoosterCodeAlreadyRedeemedException
     * @throws BoosterCodeNotAvailableYetException
     */
    public function redeem(DiscordUser $discordUser, string $rawCode): BoosterCodeRedemption
    {
        $code = BoosterCodeGenerator::normalize($rawCode);

        if ('' === $code) {
            throw new InvalidBoosterCodeException('Empty booster code submitted.', 'Saisis un code pour continuer.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($discordUser, $code): BoosterCodeRedemption {
            // FOR UPDATE: everything below is a check-then-increment on `uses`
            // and a check-then-insert on the (code, user) pair. Locking the
            // code row serializes concurrent redemptions — of the same player
            // double-clicking as much as of a crowd racing on a global code.
            $boosterCode = $this->boosterCodeRepository->findOneForUpdate($code);

            if (!$boosterCode instanceof BoosterCode || $boosterCode->isDisabled()) {
                throw new InvalidBoosterCodeException(
                    \sprintf('Booster code "%s" is unknown or revoked.', $code),
                    'Ce code n\'existe pas ou n\'est plus valide.',
                );
            }

            $now = $this->clock->now();

            if ($boosterCode->isExpired($now)) {
                throw new BoosterCodeExpiredException(
                    \sprintf('Booster code "%s" expired on %s.', $code, $boosterCode->getExpiresAt()?->format('c')),
                    'Ce code a expiré.',
                );
            }

            if ($this->boosterCodeRedemptionRepository->existsFor($boosterCode, $discordUser)) {
                throw new BoosterCodeAlreadyRedeemedException(
                    \sprintf('User "%s" already redeemed booster code "%s".', $discordUser->getDiscordId(), $code),
                    'Tu as déjà utilisé ce code.',
                );
            }

            if ($boosterCode->isExhausted()) {
                throw new BoosterCodeExhaustedException(
                    \sprintf('Booster code "%s" reached its %d use(s) limit.', $code, (int) $boosterCode->getMaxUses()),
                    'Ce code a déjà été utilisé au maximum.',
                );
            }

            $this->assertBoosterAvailable($boosterCode);

            $this->userInventoryService->creditBooster($discordUser, $boosterCode->getBooster(), $boosterCode->getQuantity());
            $boosterCode->incrementUses();

            $redemption = new BoosterCodeRedemption($boosterCode, $discordUser, $now, $boosterCode->getQuantity());
            $this->entityManager->persist($redemption);
            $this->entityManager->flush();

            return $redemption;
        });
    }

    /**
     * Checked last, and before any mutation: a code distributed ahead of a
     * release must bounce without burning a use.
     *
     * @throws BoosterCodeNotAvailableYetException
     */
    private function assertBoosterAvailable(BoosterCode $boosterCode): void
    {
        $booster = $boosterCode->getBooster();

        if (!$this->boosterAvailability->hasPublishedExtension($booster)) {
            throw new BoosterCodeNotAvailableYetException(
                \sprintf('Booster "%s" belongs to an unpublished extension.', $booster->getDisplayName()),
                'Ce code est valide, mais son pack n\'est pas encore disponible. Réessaie plus tard !',
            );
        }

        if (!$this->boosterAvailability->isDrawable($booster)) {
            throw new BoosterCodeNotAvailableYetException(
                \sprintf('Booster "%s" has no published card to draw.', $booster->getDisplayName()),
                'Ce code est valide, mais son pack n\'est pas encore disponible. Réessaie plus tard !',
            );
        }
    }
}
