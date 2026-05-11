<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterOpeningCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterOpeningCard>
 *
 * @method BoosterOpeningCard|null find($id, $lockMode = null, $lockVersion = null)
 * @method BoosterOpeningCard|null findOneBy(array $criteria, array $orderBy = null)
 * @method BoosterOpeningCard[]    findAll()
 * @method BoosterOpeningCard[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BoosterOpeningCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterOpeningCard::class);
    }
}
