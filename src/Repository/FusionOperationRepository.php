<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FusionOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FusionOperation>
 */
class FusionOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FusionOperation::class);
    }
}
