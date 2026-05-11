<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterOpening;
use App\Entity\DiscordUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterOpening>
 *
 * @method BoosterOpening|null find($id, $lockMode = null, $lockVersion = null)
 * @method BoosterOpening|null findOneBy(array $criteria, array $orderBy = null)
 * @method BoosterOpening[]    findAll()
 * @method BoosterOpening[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BoosterOpeningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterOpening::class);
    }

    /**
     * Count how many boosters a user has opened since a given time.
     */
    public function countRecentOpenings(DiscordUser $user, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('bo')
            ->select('COUNT(bo.id)')
            ->andWhere('bo.discordUser = :user')
            ->andWhere('bo.openedAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Find the most recent booster openings for a user.
     *
     * @return BoosterOpening[]
     */
    public function findRecentOpenings(DiscordUser $user, int $limit = 2): array
    {
        return $this->createQueryBuilder('bo')
            ->andWhere('bo.discordUser = :user')
            ->setParameter('user', $user)
            ->orderBy('bo.openedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Get the oldest booster opening in the last 24 hours.
     * This is used to calculate when the next free booster will be available.
     */
    public function getOldestOpeningInLast24Hours(DiscordUser $user): ?BoosterOpening
    {
        $last24Hours = new \DateTimeImmutable('-24 hours');

        return $this->createQueryBuilder('bo')
            ->andWhere('bo.discordUser = :user')
            ->andWhere('bo.openedAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $last24Hours)
            ->orderBy('bo.openedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
