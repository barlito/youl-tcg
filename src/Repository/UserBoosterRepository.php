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
 *
 * @method UserBooster|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserBooster|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserBooster[]    findAll()
 * @method UserBooster[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
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
}
