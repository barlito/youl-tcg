<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecycleOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecycleOperation>
 */
class RecycleOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecycleOperation::class);
    }
}
