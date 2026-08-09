<?php

declare(strict_types=1);

namespace App\Service\Recycle;

use App\Dto\RecycleSelectionLine;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\RecycleOperation;
use App\Entity\RecycleOperationCard;
use App\Entity\UserCard;
use App\Exception\Recycle\BoosterNotRecyclableException;
use App\Exception\Recycle\InvalidRecycleSelectionException;
use App\Exception\Recycle\NotEnoughCopiesException;
use App\Exception\Recycle\NotEnoughRecyclePointsException;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Booster\UserInventoryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Trades duplicate copies for a claimable booster, as one atomic transaction:
 * lock the UserCard rows, re-validate the quantities under lock, debit the
 * copies, credit the booster and persist the audit trail.
 *
 * Own distribution channel, like the codes: no BoosterClaim row, so the daily
 * quota is neither checked nor consumed. Points beyond the cost are lost by
 * design (no balance, no currency) — the UI says so before confirming.
 */
final readonly class RecycleService
{
    /**
     * Points a booster costs; the per-rarity scale lives on CardRarityEnum.
     */
    public const int BOOSTER_COST = 10;

    public function __construct(
        private UserCardRepository $userCardRepository,
        private UserInventoryService $userInventoryService,
        private BoosterAvailabilityService $boosterAvailability,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<RecycleSelectionLine> $selection
     *
     * @throws InvalidRecycleSelectionException
     * @throws BoosterNotRecyclableException
     * @throws NotEnoughCopiesException
     * @throws NotEnoughRecyclePointsException
     */
    public function recycle(DiscordUser $discordUser, array $selection, Booster $booster): RecycleOperation
    {
        $this->assertSelectionIsWellFormed($selection);
        // Same server-side guards as the daily claim: recycling must not be a
        // side door to event-only packs or unpublished extensions.
        $this->assertBoosterIsRecyclable($booster);

        return $this->entityManager->wrapInTransaction(function () use ($discordUser, $selection, $booster): RecycleOperation {
            $lockedRows = [];
            foreach ($this->userCardRepository->findOwnedForUpdate($discordUser, array_map(static fn (RecycleSelectionLine $line): Card => $line->card, $selection)) as $userCard) {
                // a row already in the identity map is NOT re-hydrated by the locked
                // SELECT: re-read it so the checks below see the locked DB state
                $this->entityManager->refresh($userCard);
                $lockedRows[(string) $userCard->getCard()->getId()] = $userCard;
            }

            $points = 0;
            foreach ($selection as $line) {
                $this->debit($lockedRows[(string) $line->card->getId()] ?? null, $line);
                $points += $line->getPoints();
            }

            if ($points < self::BOOSTER_COST) {
                throw new NotEnoughRecyclePointsException(
                    \sprintf('Selection is worth %d points, %d required.', $points, self::BOOSTER_COST),
                    \sprintf('Il te faut au moins %d points pour recycler (sélection actuelle : %d).', self::BOOSTER_COST, $points),
                );
            }

            $this->userInventoryService->creditBooster($discordUser, $booster);

            $operation = new RecycleOperation($discordUser, $booster, $points, $this->clock->now());
            foreach ($selection as $line) {
                $operation->addRecycleOperationCard(new RecycleOperationCard($operation, $line->card, $line->getTotalQuantity(), $line->holoQuantity));
            }

            $this->entityManager->persist($operation);
            $this->entityManager->flush();

            return $operation;
        });
    }

    /**
     * Shape checks on the client-built selection: never trust the caller.
     *
     * @param list<RecycleSelectionLine> $selection
     */
    private function assertSelectionIsWellFormed(array $selection): void
    {
        if ([] === $selection) {
            throw new InvalidRecycleSelectionException('Empty selection.', 'Sélectionne d\'abord des copies à recycler.');
        }

        $seen = [];
        foreach ($selection as $line) {
            if ($line->normalQuantity < 0 || $line->holoQuantity < 0 || $line->getTotalQuantity() < 1) {
                throw new InvalidRecycleSelectionException(
                    \sprintf('Invalid quantities for card "%s" (%d normal / %d holo).', $line->card->getName(), $line->normalQuantity, $line->holoQuantity),
                    'Sélection invalide, recharge la page et réessaie.',
                );
            }

            $cardId = (string) $line->card->getId();
            if (isset($seen[$cardId])) {
                throw new InvalidRecycleSelectionException(
                    \sprintf('Card "%s" appears twice in the selection.', $line->card->getName()),
                    'Sélection invalide, recharge la page et réessaie.',
                );
            }
            $seen[$cardId] = true;
        }
    }

    private function assertBoosterIsRecyclable(Booster $booster): void
    {
        if (!$this->boosterAvailability->isClaimable($booster)) {
            throw new BoosterNotRecyclableException(
                \sprintf('Booster "%s" is not claimable (event/code distribution only).', $booster->getDisplayName()),
                'Ce pack ne peut pas être obtenu par recyclage — il se gagne en event ou via un code.',
            );
        }

        if (!$this->boosterAvailability->hasPublishedExtension($booster)) {
            throw new BoosterNotRecyclableException(
                \sprintf('Booster "%s" belongs to an unpublished extension.', $booster->getDisplayName()),
                'Ce pack n\'est pas disponible.',
            );
        }
    }

    /**
     * Re-validates ONE line against the locked row, then debits it. Quantities
     * may have moved between display and confirmation — the locked row is the
     * only truth. Invariants kept: quantity >= holoQuantity >= 0, and at least
     * one copy of the card always stays in the collection (a 1/1 unique, at
     * quantity 1 by construction, is therefore never recyclable).
     */
    private function debit(?UserCard $userCard, RecycleSelectionLine $line): void
    {
        $cardName = $line->card->getName();

        if (!$userCard instanceof UserCard) {
            throw new NotEnoughCopiesException(
                \sprintf('Card "%s" is not owned.', $cardName),
                \sprintf('Tu ne possèdes pas « %s ».', $cardName),
            );
        }

        if ($userCard->getQuantity() - $line->getTotalQuantity() < 1) {
            throw new NotEnoughCopiesException(
                \sprintf('Recycling %d copies of "%s" would leave the collection without one (owned: %d).', $line->getTotalQuantity(), $cardName, $userCard->getQuantity()),
                \sprintf('Tu dois garder au moins un exemplaire de « %s » — seuls les doublons se recyclent.', $cardName),
            );
        }

        if ($line->holoQuantity > $userCard->getHoloQuantity()) {
            throw new NotEnoughCopiesException(
                \sprintf('Not enough holo copies of "%s" (asked %d, owned %d).', $cardName, $line->holoQuantity, $userCard->getHoloQuantity()),
                \sprintf('Tu n\'as plus assez de copies holo de « %s ».', $cardName),
            );
        }

        // quantity is the TOTAL (holo included): normal copies = quantity - holoQuantity
        if ($line->normalQuantity > $userCard->getQuantity() - $userCard->getHoloQuantity()) {
            throw new NotEnoughCopiesException(
                \sprintf('Not enough normal copies of "%s" (asked %d, owned %d).', $cardName, $line->normalQuantity, $userCard->getQuantity() - $userCard->getHoloQuantity()),
                \sprintf('Tu n\'as plus assez de copies normales de « %s ».', $cardName),
            );
        }

        $userCard
            ->setQuantity($userCard->getQuantity() - $line->getTotalQuantity())
            ->setHoloQuantity($userCard->getHoloQuantity() - $line->holoQuantity)
        ;
    }
}
