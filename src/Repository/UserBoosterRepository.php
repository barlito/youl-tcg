<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\EntityBU\UserBooster;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
