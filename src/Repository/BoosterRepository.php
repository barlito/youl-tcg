<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Booster;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booster>
 */
class BoosterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booster::class);
    }

    /**
     * @return list<Booster>
     */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('booster')
            ->join('booster.extension', 'extension')
            ->andWhere('extension.status = :status')
            ->setParameter('status', ExtensionStatusEnum::PUBLISHED)
            ->orderBy('extension.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
}
