<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
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
     * Scoped to the published catalogue, like the completion denominator: an owned
     * card put back to draft must not push a universe past 100 %.
     *
     * @return array<string, int> extension id => distinct owned published cards
     */
    public function countOwnedGroupedByExtension(DiscordUser $discordUser): array
    {
        /** @var list<array{extensionId: string, ownedCount: string|int}> $rows */
        $rows = $this->createQueryBuilder('uc')
            ->select('IDENTITY(c.extension) AS extensionId', 'COUNT(DISTINCT c.id) AS ownedCount')
            ->join('uc.card', 'c')
            ->join('c.extension', 'e')
            ->andWhere('uc.discordUser = :user')
            ->andWhere('uc.quantity > 0 OR uc.holoQuantity > 0')
            ->andWhere('c.status = :cardStatus')
            ->andWhere('e.status = :extensionStatus')
            ->setParameter('user', $discordUser)
            ->setParameter('cardStatus', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->setParameter('extensionStatus', ExtensionStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
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
     * Collection aggregates for every collector at once (leaderboard): distinct
     * cards, total copies (holos included) and holo copies — a single grouped
     * query instead of one per player.
     *
     * Scoped to the published catalogue, the same denominator the completion is
     * computed against: an unpublished card counting toward completion made the
     * leaderboard and the profile header disagree.
     *
     * @return array<string, array{distinct: int, total: int, holo: int}> discord id => stats
     */
    public function aggregateOwnedByUser(): array
    {
        /** @var list<array{userId: string, distinctCount: string|int, totalCount: string|int, holoCount: string|int}> $rows */
        $rows = $this->createQueryBuilder('uc')
            ->select(
                'IDENTITY(uc.discordUser) AS userId',
                'COUNT(DISTINCT c.id) AS distinctCount',
                // quantity already includes the holo copies (holoQuantity is a subset)
                'COALESCE(SUM(uc.quantity), 0) AS totalCount',
                'COALESCE(SUM(uc.holoQuantity), 0) AS holoCount',
            )
            ->join('uc.card', 'c')
            ->join('c.extension', 'e')
            ->andWhere('uc.quantity > 0 OR uc.holoQuantity > 0')
            ->andWhere('c.status = :cardStatus')
            ->andWhere('e.status = :extensionStatus')
            ->setParameter('cardStatus', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->setParameter('extensionStatus', ExtensionStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->groupBy('uc.discordUser')
            ->getQuery()
            ->getResult()
        ;

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['userId']] = [
                'distinct' => (int) $row['distinctCount'],
                'total' => (int) $row['totalCount'],
                'holo' => (int) $row['holoCount'],
            ];
        }

        return $stats;
    }

    /**
     * Locks the user's rows of these cards FOR UPDATE in card id order, missing rows
     * being created at 0 first; managed entities are refreshed with the locked values.
     *
     * @param list<Card> $cards
     *
     * @return array<string, UserCard> card id => locked row
     */
    public function lockForCredit(DiscordUser $discordUser, array $cards): array
    {
        $connection = $this->getEntityManager()->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('User cards can only be locked inside a transaction.');
        }

        $cardIds = array_values(array_unique(array_map(static fn (Card $card): string => (string) $card->getId(), $cards)));
        if ([] === $cardIds) {
            return [];
        }

        sort($cardIds);

        $now = new \DateTimeImmutable();
        foreach ($cardIds as $cardId) {
            $connection->executeStatement(
                'INSERT INTO user_card (discord_user_id, card_id, quantity, holo_quantity, created_at, updated_at)
                 VALUES (:user, :card, 0, 0, :now, :now)
                 ON CONFLICT (discord_user_id, card_id) DO NOTHING',
                ['user' => $discordUser->getDiscordId(), 'card' => $cardId, 'now' => $now],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
        }

        /** @var list<UserCard> $rows */
        $rows = $this->createQueryBuilder('uc')
            ->andWhere('uc.discordUser = :user')
            ->andWhere('uc.card IN (:cards)')
            ->setParameter('user', $discordUser)
            ->setParameter('cards', $cardIds)
            ->orderBy('uc.card', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult()
        ;

        $locked = [];
        foreach ($rows as $userCard) {
            $locked[(string) $userCard->getCard()->getId()] = $userCard;
        }

        return $locked;
    }

    public function countHolders(Card $card): int
    {
        return (int) $this->createQueryBuilder('userCard')
            ->select('COUNT(userCard.quantity)')
            ->andWhere('userCard.card = :card')
            ->andWhere('userCard.quantity > 0 OR userCard.holoQuantity > 0')
            ->setParameter('card', $card)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Drops the rows left at zero: they hold nothing, yet their FK would still
     * block the card's deletion.
     */
    public function deleteEmptyRows(Card $card): int
    {
        $deleted = $this->createQueryBuilder('userCard')
            ->delete()
            ->andWhere('userCard.card = :card')
            ->andWhere('userCard.quantity <= 0')
            ->andWhere('userCard.holoQuantity <= 0')
            ->setParameter('card', $card)
            ->getQuery()
            ->execute()
        ;

        return \is_int($deleted) ? $deleted : 0;
    }
}
