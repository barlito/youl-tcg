<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterOpeningCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterOpeningCard>
 */
class BoosterOpeningCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterOpeningCard::class);
    }
}
