<?php

declare(strict_types=1);

namespace App\Service\Trade;

use App\Dto\TradeLineRequest;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Entity\UserCard;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Exception\Trade\InvalidTradeOfferException;
use App\Exception\Trade\TradeConflictException;
use App\Exception\Trade\TradeException;
use App\Exception\Trade\TradeOfferInvalidatedException;
use App\Exception\Trade\TradeOfferUnacceptableException;
use App\Repository\CardRepository;
use App\Repository\TradeOfferRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\UserInventoryService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Asynchronous P2P trades, with two invariants defended at every step:
 *
 * 1. Reservation — copies engaged in a PENDING offer by their PROPOSER are
 *    reserved: creating an offer checks `owned − already reserved >= offered`,
 *    so the same copy is never engaged twice. The requested side is NEVER
 *    reserved (the receiver consented to nothing), only re-checked on
 *    acceptance.
 * 2. Atomic acceptance — one transaction, pessimistic locks on every touched
 *    UserCard row of BOTH players taken in a GLOBAL deterministic order
 *    (discordId, cardId) so crossed acceptances cannot deadlock, full
 *    re-validation under lock, then atomic debit/credit. One-of-one claims
 *    (Card.claimedBy) move in the same transaction through a conditional
 *    UPDATE.
 *
 * UserCard semantics reminder: `quantity` is the TOTAL of copies and
 * `holoQuantity` the holo sub-count, so the available normal copies are
 * `quantity − holoQuantity` and every mutation must keep
 * `quantity >= holoQuantity >= 0`.
 */
final readonly class TradeOfferService
{
    public function __construct(
        private TradeOfferRepository $tradeOfferRepository,
        private UserCardRepository $userCardRepository,
        private CardRepository $cardRepository,
        private UserInventoryService $userInventoryService,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<TradeLineRequest> $offered   cards leaving the proposer's inventory
     * @param list<TradeLineRequest> $requested cards asked from the receiver's inventory
     *
     * @throws InvalidTradeOfferException
     */
    public function create(DiscordUser $proposer, DiscordUser $receiver, array $offered, array $requested): TradeOffer
    {
        if ($proposer->getDiscordId() === $receiver->getDiscordId()) {
            throw new InvalidTradeOfferException('Self-trade refused.', 'Tu ne peux pas te proposer un échange à toi-même.');
        }

        $offered = $this->normalizeLines($offered);
        $requested = $this->normalizeLines($requested);

        if ([] === $offered) {
            throw new InvalidTradeOfferException('Empty offered side.', 'Ton offre doit proposer au moins une carte de ta collection.');
        }

        if ([] === $requested) {
            throw new InvalidTradeOfferException('Empty requested side.', 'Ton offre doit demander au moins une carte en retour.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($proposer, $receiver, $offered, $requested): TradeOffer {
            // Locks serialise concurrent creations on the same rows: without
            // them, two simultaneous offers could both pass the reservation
            // check and engage the same copy twice.
            $rows = $this->lockInventoryRows(array_map(
                static fn (TradeLineRequest $line): array => [$proposer, $line->card],
                $offered,
            ));

            $reserved = $this->tradeOfferRepository->sumReservedQuantities($proposer);

            foreach ($offered as $line) {
                $this->assertGiverCanCover(
                    $line,
                    $rows[$this->rowKey($proposer, $line->card)] ?? null,
                    $reserved,
                    $proposer,
                    static fn (string $technical, string $user): TradeException => new InvalidTradeOfferException($technical, $user),
                );
            }

            // Comfort check only (rule: incoming offers reserve nothing on the
            // receiver's side): no lock, re-validated at acceptance anyway.
            $receiverRows = $this->findRows($receiver, array_map(static fn (TradeLineRequest $line): Card => $line->card, $requested));

            foreach ($requested as $line) {
                $this->assertOwnsOutright($receiver, $line, $receiverRows[$this->rowKey($receiver, $line->card)] ?? null);
            }

            $offer = new TradeOffer()
                ->setProposer($proposer)
                ->setReceiver($receiver)
            ;

            foreach ([TradeOfferSideEnum::OFFERED->value => $offered, TradeOfferSideEnum::REQUESTED->value => $requested] as $side => $lines) {
                foreach ($lines as $line) {
                    $offer->addLine(
                        new TradeOfferLine()
                            ->setSide(TradeOfferSideEnum::from($side))
                            ->setCard($line->card)
                            ->setNormalQuantity($line->normalQuantity)
                            ->setHoloQuantity($line->holoQuantity),
                    );
                }
            }

            $this->entityManager->persist($offer);
            $this->entityManager->flush();

            return $offer;
        });
    }

    /**
     * @throws TradeException
     */
    public function accept(TradeOffer $offer, DiscordUser $actor): void
    {
        try {
            // The invalidation path COMMITS a status change then reports a
            // failure: the closure returns the exception instead of throwing
            // it, because throwing would roll the INVALIDATED status back.
            $refusal = $this->entityManager->wrapInTransaction(fn (): ?TradeException => $this->doAccept($offer, $actor));
        } catch (UniqueConstraintViolationException) {
            // concurrent insert of the same inventory row: everything rolled back
            throw new TradeConflictException('Concurrent inventory row insert.', 'Un autre échange est passé en même temps. Rien n\'a bougé, réessaie.');
        }

        if ($refusal instanceof TradeException) {
            throw $refusal;
        }
    }

    /**
     * @throws InvalidTradeOfferException
     */
    public function refuse(TradeOffer $offer, DiscordUser $actor): void
    {
        $this->resolvePending($offer, $actor, TradeOfferStatusEnum::REFUSED);
    }

    /**
     * @throws InvalidTradeOfferException
     */
    public function cancel(TradeOffer $offer, DiscordUser $actor): void
    {
        $this->resolvePending($offer, $actor, TradeOfferStatusEnum::CANCELLED);
    }

    /**
     * Display-time cleanup: flags PENDING offers that are OBVIOUSLY dead — the
     * proposer no longer holds the offered copies of THIS offer alone
     * (reservations of sibling offers are ignored on purpose: only a shortage
     * this offer cannot survive whatever happens to the others is definitive).
     * Lock-free and best-effort; the conditional UPDATE only flips PENDING
     * rows, so a concurrent resolution always wins. The authoritative check
     * stays the under-lock re-validation of accept().
     *
     * @param iterable<TradeOffer> $offers
     *
     * @return bool whether at least one offer was invalidated
     */
    public function invalidateObviouslyInfeasible(iterable $offers): bool
    {
        $invalidated = false;

        foreach ($offers as $offer) {
            if (!$offer->isPending()) {
                continue;
            }
            if (!$this->isObviouslyInfeasible($offer)) {
                continue;
            }
            if ($this->tradeOfferRepository->markInvalidatedIfPending($offer, $this->clock->now())) {
                $this->entityManager->refresh($offer);
                $invalidated = true;
            }
        }

        return $invalidated;
    }

    /**
     * What a player may still engage in a NEW offer: owned copies minus the
     * ones their pending offers already reserve. Drives the composer UI, and
     * is only indicative — create() re-checks it under lock.
     *
     * @return array<string, array{card: Card, normal: int, holo: int}> card id => free copies
     */
    public function getEngageableCopies(DiscordUser $user): array
    {
        $reserved = $this->tradeOfferRepository->sumReservedQuantities($user);
        $engageable = [];

        foreach ($this->userCardRepository->findOwnedWithCards($user) as $row) {
            $cardId = (string) $row->getCard()->getId();
            $reservedForCard = $reserved[$cardId] ?? ['normal' => 0, 'holo' => 0];
            $holo = $row->getHoloQuantity() - $reservedForCard['holo'];
            $normal = $row->getQuantity() - $row->getHoloQuantity() - $reservedForCard['normal'];

            if ($normal > 0 || $holo > 0) {
                $engageable[$cardId] = ['card' => $row->getCard(), 'normal' => max(0, $normal), 'holo' => max(0, $holo)];
            }
        }

        return $engageable;
    }

    /**
     * What a player may be ASKED for: their plain possession. Incoming offers
     * reserve nothing (the receiver consented to nothing), so this is simply
     * their inventory.
     *
     * @return array<string, array{card: Card, normal: int, holo: int}>
     */
    public function getRequestableCopies(DiscordUser $user): array
    {
        $requestable = [];

        foreach ($this->userCardRepository->findOwnedWithCards($user) as $row) {
            $normal = $row->getQuantity() - $row->getHoloQuantity();

            if ($normal > 0 || $row->getHoloQuantity() > 0) {
                $requestable[(string) $row->getCard()->getId()] = [
                    'card' => $row->getCard(),
                    'normal' => $normal,
                    'holo' => $row->getHoloQuantity(),
                ];
            }
        }

        return $requestable;
    }

    private function doAccept(TradeOffer $offer, DiscordUser $actor): ?TradeException
    {
        $offerId = $offer->getId();
        \assert(null !== $offerId);
        $locked = $this->tradeOfferRepository->findOneForUpdate($offerId);

        if (!$locked instanceof TradeOffer) {
            return new InvalidTradeOfferException('Offer not found.', 'Cette offre n\'existe plus.');
        }

        // the locked SELECT does not re-hydrate an already-loaded entity
        $this->entityManager->refresh($locked);

        if (!$locked->isPending()) {
            return new InvalidTradeOfferException('Offer is not pending.', 'Cette offre n\'est plus active.');
        }

        if ($locked->getReceiver()->getDiscordId() !== $actor->getDiscordId()) {
            return new InvalidTradeOfferException('Only the receiver may accept.', 'Seul le destinataire de l\'offre peut l\'accepter.');
        }

        $proposer = $locked->getProposer();
        $receiver = $locked->getReceiver();
        $lines = array_values($locked->getLines()->toArray());

        // Every row the trade touches (debits AND credits, both players) is
        // locked, in one global deterministic order shared by all transactions.
        $pairs = [];
        foreach ($lines as $line) {
            $pairs[] = [$locked->getGiverOf($line), $line->getCard()];
            $pairs[] = [$locked->getTakerOf($line), $line->getCard()];
        }
        $rows = $this->lockInventoryRows($pairs);

        // Full re-validation under lock, proposer side first: the remaining
        // reservations of their OTHER pending offers still apply.
        $reservedByProposer = $this->tradeOfferRepository->sumReservedQuantities($proposer, $locked);

        foreach ($locked->getOfferedLines() as $line) {
            try {
                $this->assertGiverCanCover(
                    $this->toRequest($line),
                    $rows[$this->rowKey($proposer, $line->getCard())] ?? null,
                    $reservedByProposer,
                    $proposer,
                    static fn (string $technical, string $user): TradeException => new TradeOfferInvalidatedException($technical, $user),
                );
            } catch (TradeOfferInvalidatedException $exception) {
                $locked->resolve(TradeOfferStatusEnum::INVALIDATED, $this->clock->now());
                $this->entityManager->flush();

                return new TradeOfferInvalidatedException(
                    $exception->getMessage(),
                    'Cette offre n\'est plus réalisable (le proposeur n\'a plus les cartes offertes) : elle vient d\'être invalidée.',
                );
            }
        }

        // Receiver side: their own OUTGOING pending offers reserve their
        // copies too — accepting must never break a promise they made
        // elsewhere. On failure the offer simply stays pending.
        $reservedByReceiver = $this->tradeOfferRepository->sumReservedQuantities($receiver);

        foreach ($locked->getRequestedLines() as $line) {
            try {
                $this->assertGiverCanCover(
                    $this->toRequest($line),
                    $rows[$this->rowKey($receiver, $line->getCard())] ?? null,
                    $reservedByReceiver,
                    $receiver,
                    static fn (string $technical, string $user): TradeException => new TradeOfferUnacceptableException($technical, $user),
                );
            } catch (TradeOfferUnacceptableException $exception) {
                return new TradeOfferUnacceptableException(
                    $exception->getMessage(),
                    \sprintf('%s (L\'offre reste en attente : libère ces exemplaires ou refuse-la.)', $exception->getUserMessage()),
                );
            }
        }

        // Both sides validated: debit the givers on the locked rows…
        foreach ($lines as $line) {
            $this->debit($rows[$this->rowKey($locked->getGiverOf($line), $line->getCard())] ?? throw new \LogicException('Validated row vanished.'), $line);
        }

        // …credit the takers (addCards reuses the identity-mapped locked rows
        // and creates the missing ones)…
        $this->userInventoryService->addCards($receiver, array_map($this->toCredit(...), $locked->getOfferedLines()));
        $this->userInventoryService->addCards($proposer, array_map($this->toCredit(...), $locked->getRequestedLines()));

        // …and move one-of-one claims in the SAME transaction. The conditional
        // UPDATE is the last-line guard: losing it means a concurrent writer
        // beat us despite the locks — abort everything.
        foreach ($lines as $line) {
            if (!$line->getCard()->isUnique()) {
                continue;
            }

            if (!$this->cardRepository->transferUniqueClaim($line->getCard(), $locked->getGiverOf($line), $locked->getTakerOf($line))) {
                throw new TradeConflictException(
                    \sprintf('Lost the unique claim transfer of card %s.', $line->getCard()->getId()),
                    'Un conflit est survenu sur une carte unique. Rien n\'a bougé, réessaie.',
                );
            }

            // keep the identity map in line with the DQL UPDATE above
            $line->getCard()->setClaimedBy($locked->getTakerOf($line));
        }

        $locked->resolve(TradeOfferStatusEnum::ACCEPTED, $this->clock->now());
        $this->entityManager->flush();

        return null;
    }

    private function resolvePending(TradeOffer $offer, DiscordUser $actor, TradeOfferStatusEnum $status): void
    {
        $refusal = $this->entityManager->wrapInTransaction(function () use ($offer, $actor, $status): ?TradeException {
            $offerId = $offer->getId();
            \assert(null !== $offerId);
            $locked = $this->tradeOfferRepository->findOneForUpdate($offerId);

            if (!$locked instanceof TradeOffer) {
                return new InvalidTradeOfferException('Offer not found.', 'Cette offre n\'existe plus.');
            }

            $this->entityManager->refresh($locked);

            if (!$locked->isPending()) {
                return new InvalidTradeOfferException('Offer is not pending.', 'Cette offre n\'est plus active.');
            }

            $allowedActor = TradeOfferStatusEnum::CANCELLED === $status ? $locked->getProposer() : $locked->getReceiver();

            if ($allowedActor->getDiscordId() !== $actor->getDiscordId()) {
                return new InvalidTradeOfferException(
                    \sprintf('Actor may not %s this offer.', $status->value),
                    TradeOfferStatusEnum::CANCELLED === $status
                        ? 'Seul l\'auteur de l\'offre peut l\'annuler.'
                        : 'Seul le destinataire de l\'offre peut la refuser.',
                );
            }

            $locked->resolve($status, $this->clock->now());
            $this->entityManager->flush();

            return null;
        });

        if ($refusal instanceof TradeException) {
            throw $refusal;
        }
    }

    /**
     * Merges duplicate cards and rejects nonsense quantities. Returned lines
     * all move at least one copy.
     *
     * @param list<TradeLineRequest> $lines
     *
     * @return list<TradeLineRequest>
     */
    private function normalizeLines(array $lines): array
    {
        $byCard = [];

        foreach ($lines as $line) {
            if ($line->normalQuantity < 0 || $line->holoQuantity < 0) {
                throw new InvalidTradeOfferException('Negative quantity.', \sprintf('Quantité invalide pour « %s ».', $line->card->getName()));
            }

            if (0 === $line->getTotalQuantity()) {
                continue;
            }

            $cardId = (string) $line->card->getId();
            $merged = $byCard[$cardId] ?? new TradeLineRequest($line->card, 0, 0);
            $byCard[$cardId] = new TradeLineRequest(
                $line->card,
                $merged->normalQuantity + $line->normalQuantity,
                $merged->holoQuantity + $line->holoQuantity,
            );
        }

        return array_values($byCard);
    }

    /**
     * Checks a giver can cover a line out of a locked row: possession minus
     * the reservations of their (other) pending offers, per finish, plus the
     * one-of-one claim coherence.
     *
     * @param array<string, array{normal: int, holo: int}> $reserved
     * @param callable(string, string): TradeException     $refusalFactory
     */
    private function assertGiverCanCover(
        TradeLineRequest $line,
        ?UserCard $row,
        array $reserved,
        DiscordUser $giver,
        callable $refusalFactory,
    ): void {
        $cardId = (string) $line->card->getId();
        $ownedHolo = $row?->getHoloQuantity() ?? 0;
        $ownedNormal = ($row?->getQuantity() ?? 0) - $ownedHolo;
        $reservedForCard = $reserved[$cardId] ?? ['normal' => 0, 'holo' => 0];

        if ($ownedNormal < $line->normalQuantity || $ownedHolo < $line->holoQuantity) {
            throw $refusalFactory(
                \sprintf('Card %s: owned %d/%d✦, needed %d/%d✦.', $cardId, $ownedNormal, $ownedHolo, $line->normalQuantity, $line->holoQuantity),
                \sprintf('Pas assez d\'exemplaires de « %s » dans la collection de %s.', $line->card->getName(), $giver->getUsername()),
            );
        }

        if (
            $ownedNormal - $reservedForCard['normal'] < $line->normalQuantity
            || $ownedHolo - $reservedForCard['holo'] < $line->holoQuantity
        ) {
            throw $refusalFactory(
                \sprintf('Card %s: copies already engaged in another pending offer.', $cardId),
                \sprintf('Des exemplaires de « %s » sont déjà engagés dans une autre offre en attente.', $line->card->getName()),
            );
        }

        if ($line->card->isUnique()) {
            // fresh read: the identity-mapped card may carry a stale claimedBy
            $this->entityManager->refresh($line->card);

            if ($line->card->getClaimedBy()?->getDiscordId() !== $giver->getDiscordId()) {
                throw $refusalFactory(
                    \sprintf('Unique card %s is not claimed by its giver.', $cardId),
                    \sprintf('« %s » (exemplaire unique) n\'appartient plus à %s.', $line->card->getName(), $giver->getUsername()),
                );
            }
        }
    }

    /**
     * Creation-time comfort check of the requested side: plain possession, no
     * reservation involved (rule: only proposers reserve).
     */
    private function assertOwnsOutright(DiscordUser $owner, TradeLineRequest $line, ?UserCard $row): void
    {
        $ownedHolo = $row?->getHoloQuantity() ?? 0;
        $ownedNormal = ($row?->getQuantity() ?? 0) - $ownedHolo;

        if ($ownedNormal < $line->normalQuantity || $ownedHolo < $line->holoQuantity) {
            throw new InvalidTradeOfferException(
                \sprintf('Receiver misses card %s.', $line->card->getId()),
                \sprintf('%s ne possède pas les exemplaires demandés de « %s ».', $owner->getUsername(), $line->card->getName()),
            );
        }

        if ($line->card->isUnique() && $line->card->getClaimedBy()?->getDiscordId() !== $owner->getDiscordId()) {
            throw new InvalidTradeOfferException(
                \sprintf('Unique card %s is not claimed by the receiver.', $line->card->getId()),
                \sprintf('« %s » (exemplaire unique) n\'appartient pas à %s.', $line->card->getName(), $owner->getUsername()),
            );
        }
    }

    /**
     * Locks the given inventory rows with FOR UPDATE, deduplicated and in the
     * GLOBAL deterministic order (discordId, cardId): every trade transaction
     * acquires its row locks in the same sequence, so two crossed acceptances
     * wait for each other instead of deadlocking. Missing rows (the taker does
     * not own the card yet) are simply absent from the result.
     *
     * @param list<array{DiscordUser, Card}> $pairs
     *
     * @return array<string, UserCard> "discordId|cardId" => locked, refreshed row
     */
    private function lockInventoryRows(array $pairs): array
    {
        $unique = [];
        foreach ($pairs as [$user, $card]) {
            $unique[$this->rowKey($user, $card)] = [$user, $card];
        }
        ksort($unique);

        $rows = [];
        foreach ($unique as $key => [$user, $card]) {
            $row = $this->userCardRepository->findOneForUpdate($user, $card);

            if ($row instanceof UserCard) {
                // the locked SELECT does not re-hydrate an already-loaded entity
                $this->entityManager->refresh($row);
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<Card> $cards
     *
     * @return array<string, UserCard> "discordId|cardId" => row (no lock)
     */
    private function findRows(DiscordUser $user, array $cards): array
    {
        $rows = [];
        foreach ($this->userCardRepository->findBy(['discordUser' => $user, 'card' => $cards]) as $row) {
            $rows[$this->rowKey($user, $row->getCard())] = $row;
        }

        return $rows;
    }

    private function debit(UserCard $row, TradeOfferLine $line): void
    {
        $row->setQuantity($row->getQuantity() - $line->getTotalQuantity());
        $row->setHoloQuantity($row->getHoloQuantity() - $line->getHoloQuantity());

        if ($row->getQuantity() < $row->getHoloQuantity() || $row->getHoloQuantity() < 0) {
            throw new \LogicException(\sprintf('Inventory invariant broken on card %s.', $row->getCard()->getId()));
        }
    }

    private function toRequest(TradeOfferLine $line): TradeLineRequest
    {
        return new TradeLineRequest($line->getCard(), $line->getNormalQuantity(), $line->getHoloQuantity());
    }

    /**
     * @return array{card: Card, quantity: int, holoQuantity: int}
     */
    private function toCredit(TradeOfferLine $line): array
    {
        return ['card' => $line->getCard(), 'quantity' => $line->getTotalQuantity(), 'holoQuantity' => $line->getHoloQuantity()];
    }

    private function isObviouslyInfeasible(TradeOffer $offer): bool
    {
        $offeredLines = $offer->getOfferedLines();
        $rows = $this->findRows($offer->getProposer(), array_map(static fn (TradeOfferLine $line): Card => $line->getCard(), $offeredLines));

        foreach ($offeredLines as $line) {
            $row = $rows[$this->rowKey($offer->getProposer(), $line->getCard())] ?? null;
            $ownedHolo = $row?->getHoloQuantity() ?? 0;
            $ownedNormal = ($row?->getQuantity() ?? 0) - $ownedHolo;

            if ($ownedNormal < $line->getNormalQuantity() || $ownedHolo < $line->getHoloQuantity()) {
                return true;
            }

            if (
                $line->getCard()->isUnique()
                && $line->getCard()->getClaimedBy()?->getDiscordId() !== $offer->getProposer()->getDiscordId()
            ) {
                return true;
            }
        }

        return false;
    }

    private function rowKey(DiscordUser $user, Card $card): string
    {
        return \sprintf('%s|%s', $user->getDiscordId(), $card->getId());
    }
}
