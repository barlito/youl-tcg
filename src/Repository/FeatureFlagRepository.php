<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeatureFlag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeatureFlag>
 */
class FeatureFlagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeatureFlag::class);
    }

    /**
     * @return array<string, bool> feature name => enabled
     */
    public function findStates(): array
    {
        /** @var list<array{name: string, enabled: bool}> $rows */
        $rows = $this->createQueryBuilder('flag')
            ->select('flag.name', 'flag.enabled')
            ->getQuery()
            ->getArrayResult()
        ;

        return array_column($rows, 'enabled', 'name');
    }
}
