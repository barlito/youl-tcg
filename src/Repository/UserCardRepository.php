<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserCard>
 */
class UserCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCard::class);
    }

    /**
     * Owned inventory entries with the card and its extension eagerly hydrated for
     * the collection grid, sorted by rarity (rarest first) then name.
     *
     * @return list<UserCard>
     */
    public function findOwnedWithCards(DiscordUser $discordUser, ?Extension $extension = null): array
    {
        $queryBuilder = $this->createQueryBuilder('uc')
            ->join('uc.card', 'c')
            ->addSelect('c')
            ->join('c.extension', 'e')
            ->addSelect('e')
            ->andWhere('uc.discordUser = :user')
            ->andWhere('uc.quantity > 0 OR uc.holoQuantity > 0')
            ->setParameter('user', $discordUser)
        ;

        if ($extension instanceof Extension) {
            $queryBuilder
                ->andWhere('c.extension = :extension')
                ->setParameter('extension', $extension)
            ;
        }

        /** @var list<UserCard> $cards */
        $cards = $queryBuilder->getQuery()->getResult();

        // Rarity is a string-backed enum (alphabetical ≠ rarity order), so rank it
        // in PHP: rarest first, ties broken by name. The owned set is small.
        usort(
            $cards,
            static fn (UserCard $a, UserCard $b): int => CardRarityEnum::compareRarestFirst($a->getCard()->getRarity(), $b->getCard()->getRarity())
                ?: $a->getCard()->getName() <=> $b->getCard()->getName(),
        );

        return $cards;
    }

    /**
     * @return array<string, int> extension id => distinct owned cards
     */
    public function countOwnedGroupedByExtension(DiscordUser $discordUser): array
    {
        /** @var list<array{extensionId: string, ownedCount: string|int}> $rows */
        $rows = $this->createQueryBuilder('uc')
            ->select('IDENTITY(c.extension) AS extensionId', 'COUNT(DISTINCT c.id) AS ownedCount')
            ->join('uc.card', 'c')
            ->andWhere('uc.discordUser = :user')
            ->andWhere('uc.quantity > 0 OR uc.holoQuantity > 0')
            ->setParameter('user', $discordUser)
            ->groupBy('c.extension')
            ->getQuery()
            ->getResult()
        ;

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['extensionId']] = (int) $row['ownedCount'];
        }

        return $counts;
    }

    /**
     * Ids of the distinct cards the user owns (any quantity), used to flag
     * freshly obtained cards after a booster opening.
     *
     * @return list<string>
     */
    public function findOwnedCardIds(DiscordUser $discordUser): array
    {
        /** @var list<array{cardId: string}> $rows */
        $rows = $this->createQueryBuilder('uc')
            ->select('IDENTITY(uc.card) AS cardId')
            ->andWhere('uc.discordUser = :user')
            ->andWhere('uc.quantity > 0 OR uc.holoQuantity > 0')
            ->setParameter('user', $discordUser)
            ->getQuery()
            ->getResult()
        ;

        return array_map(static fn (array $row): string => (string) $row['cardId'], $rows);
    }

    /**
     * Inventory entries with at least one recyclable duplicate (quantity > 1:
     * the last copy always stays), card and extension eagerly hydrated for the
     * recycle page, sorted rarest first then by name.
     *
     * @return list<UserCard>
     */
    public function findRecyclableWithCards(DiscordUser $discordUser): array
    {
        /** @var list<UserCard> $cards */
        $cards = $this->createQueryBuilder('uc')
            ->join('uc.card', 'c')
            ->addSelect('c')
            ->join('c.extension', 'e')
            ->addSelect('e')
            ->andWhere('uc.discordUser = :user')
            ->andWhere('uc.quantity > 1')
            ->setParameter('user', $discordUser)
            ->getQuery()
            ->getResult()
        ;

        usort(
            $cards,
            static fn (UserCard $a, UserCard $b): int => CardRarityEnum::compareRarestFirst($a->getCard()->getRarity(), $b->getCard()->getRarity())
                ?: $a->getCard()->getName() <=> $b->getCard()->getName(),
        );

        return $cards;
    }

    /**
     * Pessimistic write lock on the user's rows for the given cards, so a
     * recycle debit re-validates quantities against what concurrent operations
     * left. Requires an active transaction. Rows are locked in card id order:
     * two concurrent selections lock in the same sequence, never a deadlock.
     *
     * @param list<Card> $cards
     *
     * @return list<UserCard>
     */
    public function findOwnedForUpdate(DiscordUser $discordUser, array $cards): array
    {
        return $this->createQueryBuilder('uc')
            ->andWhere('uc.discordUser = :user')
            ->andWhere('uc.card IN (:cards)')
            ->setParameter('user', $discordUser)
            ->setParameter('cards', $cards)
            ->orderBy('IDENTITY(uc.card)', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult()
        ;
    }

    /**
     * Total number of cards owned, holo included (collection banner stat).
     */
    public function sumOwnedQuantities(DiscordUser $discordUser): int
    {
        return (int) $this->createQueryBuilder('uc')
            ->select('COALESCE(SUM(uc.quantity + uc.holoQuantity), 0)')
            ->andWhere('uc.discordUser = :user')
            ->setParameter('user', $discordUser)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }
}
