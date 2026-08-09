<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterOpening;
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
