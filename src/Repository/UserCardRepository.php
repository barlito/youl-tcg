<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\EntityBU\UserCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserCard>
 *
 * @method UserCard|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserCard|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserCard[]    findAll()
 * @method UserCard[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCard::class);
    }
}
