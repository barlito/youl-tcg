<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Booster;
use App\Entity\DiscordUser;
use App\Entity\UserBooster;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserBooster>
 */
class UserBoosterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBooster::class);
    }

    /**
     * Pessimistic write lock to guard the quantity against concurrent
     * openings (double-click / parallel requests). Requires an active
     * transaction.
     */
    public function findOneForUpdate(DiscordUser $discordUser, Booster $booster): ?UserBooster
    {
        return $this->createQueryBuilder('userBooster')
            ->andWhere('userBooster.discordUser = :discordUser')
            ->andWhere('userBooster.booster = :booster')
            ->setParameter('discordUser', $discordUser)
            ->setParameter('booster', $booster)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;
    }

    public function countHolders(Booster $booster): int
    {
        return (int) $this->createQueryBuilder('userBooster')
            ->select('COUNT(userBooster.quantity)')
            ->andWhere('userBooster.booster = :booster')
            ->andWhere('userBooster.quantity > 0')
            ->setParameter('booster', $booster)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Drops the rows left at zero once a player opened every copy: they hold
     * nothing, yet their FK would still block the booster's deletion.
     */
    public function deleteEmptyRows(Booster $booster): int
    {
        $deleted = $this->createQueryBuilder('userBooster')
            ->delete()
            ->andWhere('userBooster.booster = :booster')
            ->andWhere('userBooster.quantity <= 0')
            ->setParameter('booster', $booster)
            ->getQuery()
            ->execute()
        ;

        return \is_int($deleted) ? $deleted : 0;
    }
}
