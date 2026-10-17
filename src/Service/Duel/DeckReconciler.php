<?php

declare(strict_types=1);

namespace App\Service\Duel;

use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\UserCard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pull model: on every deck read the stored deck is brought in line with what the owner holds NOW, and what changed is
 * kept on the deck as a notice. A lost kind (holo / normal) switches to the remaining one, the last copy removes the card.
 */
final readonly class DeckReconciler
{
    public const string REMOVED = 'removed';

    public const string SWITCHED_TO_NORMAL = 'switchedToNormal';

    public const string SWITCHED_TO_HOLO = 'switchedToHolo';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Idempotent: a deck already in line is left untouched (no write).
     *
     * @param array<string, UserCard> $collection the owner's duel collection, card id => row
     */
    public function reconcile(Deck $deck, array $collection): void
    {
        if ([] === $this->diff($deck, $collection)) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($deck, $collection): void {
            // serializes concurrent reads of the same deck: the second one finds nothing left to change
            $this->entityManager->lock($deck, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($deck);

            $changes = [];
            foreach ($this->diff($deck, $collection) as [$card, $type]) {
                match ($type) {
                    self::REMOVED => $deck->removeCard($card),
                    default => $deck->setHolo($card, self::SWITCHED_TO_HOLO === $type),
                };
                $changes[] = ['card' => (string) $card->getId(), 'name' => $card->getName(), 'type' => $type, 'at' => new \DateTimeImmutable()->format(\DATE_ATOM)];
            }
            $deck->recordChanges($changes);
        });
    }

    /**
     * @param array<string, UserCard> $collection
     *
     * @return list<array{0: Card, 1: string}>
     */
    private function diff(Deck $deck, array $collection): array
    {
        $chosen = $deck->getHoloCardIds();
        $diff = [];
        foreach ($this->sortedCards($deck) as $card) {
            $id = (string) $card->getId();
            $row = $collection[$id] ?? null;
            if (!DeckOwnership::ownsAny($row)) {
                $diff[] = [$card, self::REMOVED];
                continue;
            }

            $wasHolo = \in_array($id, $chosen, true);
            $holo = DeckOwnership::playsHolo($row, $wasHolo);
            if ($holo !== $wasHolo) {
                $diff[] = [$card, $holo ? self::SWITCHED_TO_HOLO : self::SWITCHED_TO_NORMAL];
            }
        }

        return $diff;
    }

    /**
     * The join table has no order: sort by name so the notice lists the changes the same way on every read.
     *
     * @return list<Card>
     */
    private function sortedCards(Deck $deck): array
    {
        $cards = $deck->getCards();
        usort($cards, static fn (Card $a, Card $b): int => [$a->getName(), (string) $a->getId()] <=> [$b->getName(), (string) $b->getId()]);

        return $cards;
    }
}
