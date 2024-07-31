<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Extension;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Extension>
 *
 * @method Extension|null find($id, $lockMode = null, $lockVersion = null)
 * @method Extension|null findOneBy(array $criteria, array $orderBy = null)
 * @method Extension[]    findAll()
 * @method Extension[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ExtensionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Extension::class);
    }
}
