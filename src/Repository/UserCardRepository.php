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
     * Pessimistic write lock on one inventory row, guarding its quantities
     * against concurrent trades/openings. Requires an active transaction.
     * NB: the locked SELECT does NOT re-hydrate an entity already in the
     * identity map — callers must refresh() the returned row.
     */
    public function findOneForUpdate(DiscordUser $discordUser, Card $card): ?UserCard
    {
        return $this->createQueryBuilder('userCard')
            ->andWhere('userCard.discordUser = :discordUser')
            ->andWhere('userCard.card = :card')
            ->setParameter('discordUser', $discordUser)
            ->setParameter('card', $card)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;
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
}
