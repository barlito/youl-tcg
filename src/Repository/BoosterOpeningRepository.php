<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterOpening;
use App\Entity\DiscordUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterOpening>
 */
class BoosterOpeningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterOpening::class);
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    public function countByUser(DiscordUser $user): int
    {
        return $this->count(['discordUser' => $user]);
    }

    /**
     * @return array<string, int> booster id => openings by this user
     */
    public function countPerBoosterForUser(DiscordUser $user): array
    {
        /** @var list<array{boosterId: string, openings: int|string}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.booster) AS boosterId', 'COUNT(o.id) AS openings')
            ->where('o.discordUser = :user')
            ->setParameter('user', $user)
            ->groupBy('o.booster')
            ->getQuery()
            ->getArrayResult()
        ;

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['boosterId']] = (int) $row['openings'];
        }

        return $counts;
    }

    /**
     * One page of the player's opening history, newest first, with everything
     * the page renders (cards, their extension, the booster) fetch-joined.
     *
     * @return list<BoosterOpening>
     */
    public function findHistoryPage(DiscordUser $user, int $page, int $perPage): array
    {
        // paginate WITHOUT the collection join (LIMIT would slice card rows,
        // not openings), then hydrate the collections on the fetched entities
        $openings = $this->findBy(
            ['discordUser' => $user],
            ['openedAt' => 'DESC', 'id' => 'DESC'],
            $perPage,
            ($page - 1) * $perPage,
        );

        if ([] === $openings) {
            return [];
        }

        $this->createQueryBuilder('o')
            ->addSelect('oc', 'c', 'ce', 'b', 'be')
            ->leftJoin('o.boosterOpeningCards', 'oc')
            ->leftJoin('oc.card', 'c')
            ->leftJoin('c.extension', 'ce')
            ->join('o.booster', 'b')
            ->join('b.extension', 'be')
            ->where('o IN (:openings)')
            ->setParameter('openings', $openings)
            ->getQuery()
            ->getResult()
        ;

        // same managed instances, collections now initialized: the first
        // query's ordering still holds
        return $openings;
    }

    /**
     * Boosters opened per player, one grouped query (leaderboard stat).
     *
     * @return array<string, int> discord id => openings
     */
    public function countGroupedByUser(): array
    {
        /** @var list<array{userId: string, openingCount: string|int}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.discordUser) AS userId', 'COUNT(o.id) AS openingCount')
            ->groupBy('o.discordUser')
            ->getQuery()
            ->getResult()
        ;

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['userId']] = (int) $row['openingCount'];
        }

        return $counts;
    }
}
