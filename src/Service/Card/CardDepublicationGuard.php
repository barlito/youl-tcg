<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Enum\Entity\CardStatusEnum;
use App\Repository\UserCardRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A card a player already owns must stay PUBLISHED: unpublishing it would
 * silently pull it out of their collection.
 */
final readonly class CardDepublicationGuard
{
    public function __construct(
        private UserCardRepository $userCardRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Why the card can no longer go back to DRAFT, null while it still can.
     */
    public function blockReason(Card $card): ?string
    {
        if (!$this->entityManager->contains($card)) {
            return null; // never persisted: nobody can hold it
        }

        $holders = $this->userCardRepository->countHolders($card);
        if ($holders > 0) {
            return \sprintf('%d joueur(s) possèdent cette carte : elle ne peut plus être dépubliée.', $holders);
        }

        $claimedBy = $card->getClaimedBy();
        if ($claimedBy instanceof DiscordUser) {
            return \sprintf('Carte unique (1/1) déjà tirée par %s : elle ne peut plus être dépubliée.', $claimedBy);
        }

        return null;
    }

    /**
     * True when the pending change takes a PUBLISHED card back to DRAFT,
     * compared with the value loaded from the database.
     */
    public function isDepublication(Card $card): bool
    {
        if (CardStatusEnum::DRAFT !== $card->getStatus()) {
            return false;
        }

        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($card)['status'] ?? null;
        // hydrated rows hold the enum, freshly inserted ones may hold the raw value
        if (\is_int($original)) {
            $original = CardStatusEnum::tryFrom($original);
        }

        return CardStatusEnum::PUBLISHED === $original;
    }
}
