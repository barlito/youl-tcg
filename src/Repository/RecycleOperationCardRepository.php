<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecycleOperationCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecycleOperationCard>
 */
class RecycleOperationCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecycleOperationCard::class);
    }
}
