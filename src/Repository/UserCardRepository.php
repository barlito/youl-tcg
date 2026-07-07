<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        $rank = array_flip(array_map(
            static fn (CardRarityEnum $rarity): string => $rarity->value,
            CardRarityEnum::ascending(),
        ));
        usort(
            $cards,
            static fn (UserCard $a, UserCard $b): int => ($rank[$b->getCard()->getRarity()->value] <=> $rank[$a->getCard()->getRarity()->value])
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
