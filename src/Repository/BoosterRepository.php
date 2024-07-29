<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\EntityBU\Booster;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booster>
 *
 * @method Booster|null find($id, $lockMode = null, $lockVersion = null)
 * @method Booster|null findOneBy(array $criteria, array $orderBy = null)
 * @method Booster[]    findAll()
 * @method Booster[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BoosterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booster::class);
    }
}
