<?php

declare(strict_types=1);

namespace App\Service\Extension;

use App\Entity\Card;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A universe whose cards players already own must stay PUBLISHED:
 * unpublishing it would silently hide those cards from their collections.
 */
final readonly class ExtensionDepublicationGuard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Why the extension can no longer go back to DRAFT, null while it still can.
     */
    public function blockReason(Extension $extension): ?string
    {
        if (!$this->entityManager->contains($extension)) {
            return null; // never persisted: nobody can hold its cards
        }

        $holders = $this->countHolders($extension);
        if ($holders > 0) {
            return \sprintf('%d joueur(s) possèdent des cartes de cet univers : il ne peut plus être dépublié.', $holders);
        }

        return null;
    }

    /**
     * True when the pending change takes a PUBLISHED extension back to DRAFT,
     * compared with the value loaded from the database.
     */
    public function isDepublication(Extension $extension): bool
    {
        if (ExtensionStatusEnum::DRAFT !== $extension->getStatus()) {
            return false;
        }

        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($extension)['status'] ?? null;
        // hydrated rows hold the enum, freshly inserted ones may hold the raw value
        if (\is_int($original)) {
            $original = ExtensionStatusEnum::tryFrom($original);
        }

        return ExtensionStatusEnum::PUBLISHED === $original;
    }

    /**
     * Distinct players holding a card of the universe, drawn 1/1 holders included.
     */
    private function countHolders(Extension $extension): int
    {
        /** @var list<string> $owners */
        $owners = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(userCard.discordUser)')
            ->from(UserCard::class, 'userCard')
            ->join('userCard.card', 'card')
            ->andWhere('card.extension = :extension')
            ->andWhere('userCard.quantity > 0 OR userCard.holoQuantity > 0')
            ->setParameter('extension', $extension)
            ->getQuery()
            ->getSingleColumnResult()
        ;

        /** @var list<string> $uniqueHolders */
        $uniqueHolders = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(card.claimedBy)')
            ->from(Card::class, 'card')
            ->andWhere('card.extension = :extension')
            ->andWhere('card.claimedBy IS NOT NULL')
            ->setParameter('extension', $extension)
            ->getQuery()
            ->getSingleColumnResult()
        ;

        return \count(array_unique([...$owners, ...$uniqueHolders]));
    }
}
