<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscordUser>
 */
class DiscordUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscordUser::class);
    }

    /**
     * @return list<array{discordId: string, username: string, distinctCardCount: int}>
     */
    public function findOthersOrderedByUsername(DiscordUser $user): array
    {
        /** @var list<array{discordId: string, username: string, distinctCardCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('discordUser')
            ->select('discordUser.discordId AS discordId', 'discordUser.username AS username', 'COUNT(userCard.card) AS distinctCardCount')
            ->leftJoin('discordUser.userCards', 'userCard', 'WITH', 'userCard.quantity > 0')
            ->andWhere('discordUser.discordId != :self')
            ->setParameter('self', $user->getDiscordId())
            ->groupBy('discordUser.discordId', 'discordUser.username')
            ->orderBy('LOWER(discordUser.username)', 'ASC')
            ->getQuery()
            ->getArrayResult()
        ;

        return array_map(static fn (array $row): array => [...$row, 'distinctCardCount' => (int) $row['distinctCardCount']], $rows);
    }

    // grouped totals for the admin list: the entity getters lazy-load both collections per row
    /**
     * @return array<string, array{distinct: int, copies: int, boosters: int}> discord id => totals
     */
    public function findInventoryTotals(): array
    {
        $totals = [];
        $entityManager = $this->getEntityManager();

        /** @var list<array{id: string, distinct: int|string|null, copies: int|string|null}> $cards */
        $cards = $entityManager->createQuery('SELECT IDENTITY(uc.discordUser) AS id, SUM(CASE WHEN uc.quantity > 0 THEN 1 ELSE 0 END) AS distinct, SUM(uc.quantity) AS copies FROM App\Entity\UserCard uc GROUP BY uc.discordUser')->getArrayResult();
        foreach ($cards as $row) {
            $totals[$row['id']] = ['distinct' => (int) $row['distinct'], 'copies' => (int) $row['copies'], 'boosters' => 0];
        }

        /** @var list<array{id: string, boosters: int|string|null}> $boosters */
        $boosters = $entityManager->createQuery('SELECT IDENTITY(ub.discordUser) AS id, SUM(ub.quantity) AS boosters FROM App\Entity\UserBooster ub GROUP BY ub.discordUser')->getArrayResult();
        foreach ($boosters as $row) {
            $totals[$row['id']] ??= ['distinct' => 0, 'copies' => 0, 'boosters' => 0];
            $totals[$row['id']]['boosters'] = (int) $row['boosters'];
        }

        return $totals;
    }
}
