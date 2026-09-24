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
use App\Enum\FeatureEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Exception\Recycle\BoosterNotRecyclableException;
use App\Exception\Recycle\InvalidRecycleSelectionException;
use App\Exception\Recycle\NotEnoughCopiesException;
use App\Exception\Recycle\NotEnoughRecyclePointsException;
use App\Exception\Recycle\RecyclingClosedException;
use App\Repository\TradeOfferRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Booster\UserInventoryService;
use App\Service\Feature\FeatureFlags;
use App\Service\Notification\NotificationService;
use App\Service\Realtime\UserEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Trades duplicate copies for a retrievable booster, as one atomic transaction:
 * credit the booster, lock the UserCard rows, re-validate the quantities under
 * lock, debit the copies and persist the audit trail.
 *
 * Own distribution channel, like the codes: no BoosterClaim row, so the daily
 * quota is neither checked nor consumed. Every full tranche of BOOSTER_COST
 * points is worth one copy of the single chosen booster; the remainder is lost
 * by design (no balance, no currency) — the UI says so before confirming.
 */
final readonly class RecycleService
{
    /**
     * Points a booster costs; the per-rarity scale lives on CardRarityEnum.
     */
    public const int BOOSTER_COST = 10;

    /**
     * Copies of the chosen booster a selection is worth: one per full tranche.
     */
    public static function boosterCountFor(int $points): int
    {
        return intdiv(max(0, $points), self::BOOSTER_COST);
    }

    /**
     * Points left below the last full tranche: lost on confirmation.
     */
    public static function lostPointsFor(int $points): int
    {
        return max(0, $points) % self::BOOSTER_COST;
    }

    public function __construct(
        private UserCardRepository $userCardRepository,
        private TradeOfferRepository $tradeOfferRepository,
        private UserInventoryService $userInventoryService,
        private BoosterAvailabilityService $boosterAvailability,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private FeatureFlags $featureFlags,
        private UserEventPublisher $userEventPublisher,
        private NotificationService $notificationService,
    ) {
    }

    /**
     * @param list<RecycleSelectionLine> $selection
     *
     * @throws RecyclingClosedException
     * @throws InvalidRecycleSelectionException
     * @throws BoosterNotRecyclableException
     * @throws NotEnoughCopiesException
     * @throws NotEnoughRecyclePointsException
     */
    public function recycle(DiscordUser $discordUser, array $selection, Booster $booster): RecycleOperation
    {
        if (!$this->featureFlags->isEnabled(FeatureEnum::RECYCLING)) {
            throw new RecyclingClosedException('Recycling feature is disabled.', 'Le recyclage est momentanément fermé.');
        }

        $this->assertSelectionIsWellFormed($selection);
        $this->assertBoosterIsRecyclable($booster);

        // points only depend on the selection and the scale, not on the owned
        // quantities: checked before any lock
        $points = array_sum(array_map(static fn (RecycleSelectionLine $line): int => $line->getPoints(), $selection));
        if ($points < self::BOOSTER_COST) {
            throw new NotEnoughRecyclePointsException(
                \sprintf('Selection is worth %d points, %d required.', $points, self::BOOSTER_COST),
                \sprintf('Il te faut au moins %d points pour recycler (sélection actuelle : %d).', self::BOOSTER_COST, $points),
            );
        }

        $boosterCount = self::boosterCountFor($points);

        $operation = $this->entityManager->wrapInTransaction(function () use ($discordUser, $selection, $booster, $points, $boosterCount): RecycleOperation {
            // booster row first, then the card rows: the same lock order as an opening
            $this->userInventoryService->creditBooster($discordUser, $booster, $boosterCount);

            $lockedRows = $this->userCardRepository->lockForDebit(
                $discordUser,
                array_map(static fn (RecycleSelectionLine $line): Card => $line->card, $selection),
            );
            // read under the row locks: an offer created meanwhile has committed
            $engaged = $this->tradeOfferRepository->findEngagedCardIds($discordUser);
            foreach ($selection as $line) {
                $cardId = (string) $line->card->getId();
                $this->debit($lockedRows[$cardId] ?? null, $line, isset($engaged[$cardId]));
            }

            $operation = new RecycleOperation($discordUser, $booster, $points, $boosterCount, $this->clock->now());
            foreach ($selection as $line) {
                $operation->addRecycleOperationCard(new RecycleOperationCard($operation, $line->card, $line->getTotalQuantity(), $line->holoQuantity));
            }

            $this->entityManager->persist($operation);
            $this->entityManager->flush();

            return $operation;
        });

        // post-commit: a rolled back action never reaches the browser
        $this->userEventPublisher->publish($discordUser, UserEventEnum::INVENTORY_CHANGED);
        $this->notificationService->notify($discordUser, NotificationTypeEnum::BOOSTER_CREDITED, [
            'boosterName' => $booster->getDisplayName(),
            'quantity' => $boosterCount,
            'channel' => 'recycle',
        ], alreadyRead: true);

        return $operation;
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

    /**
     * Same single check as a daily claim or a streak reward: recycling must not
     * be a side door to event-only, unpublished or undrawable packs.
     */
    private function assertBoosterIsRecyclable(Booster $booster): void
    {
        if (!$this->boosterAvailability->isRetrievable($booster)) {
            throw new BoosterNotRecyclableException(
                \sprintf('Booster "%s" is not retrievable (not claimable, unpublished extension or nothing to draw).', $booster->getDisplayName()),
                'Ce pack ne peut pas être obtenu par recyclage pour le moment.',
            );
        }
    }

    /**
     * Re-validates ONE line against the locked row, then debits it. Quantities
     * may have moved between display and confirmation — the locked row is the
     * only truth. Invariants kept: quantity >= holoQuantity >= 0, and at least
     * one copy of the card always stays in the collection (a 1/1 unique, at
     * quantity 1 by construction, is therefore never recyclable). A card
     * the player offers in a pending trade offer is not recyclable at all.
     */
    private function debit(?UserCard $userCard, RecycleSelectionLine $line, bool $engaged): void
    {
        $cardName = $line->card->getName();

        if (!$userCard instanceof UserCard) {
            throw new NotEnoughCopiesException(
                \sprintf('Card "%s" is not owned.', $cardName),
                \sprintf('Tu ne possèdes pas « %s ».', $cardName),
            );
        }

        if ($engaged) {
            throw new NotEnoughCopiesException(
                \sprintf('Card "%s" is engaged in a pending trade offer.', $cardName),
                \sprintf('« %s » est engagée dans une offre d\'échange en attente que tu as proposée : elle ne peut pas être recyclée tant que l\'offre n\'est pas acceptée, refusée ou annulée.', $cardName),
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
