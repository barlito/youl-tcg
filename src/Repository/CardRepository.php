<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 *
 * @method Card|null find($id, $lockMode = null, $lockVersion = null)
 * @method Card|null findOneBy(array $criteria, array $orderBy = null)
 * @method Card[]    findAll()
 * @method Card[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    public function findRandomCardId(int $maxResult): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.id')
            ->join('c.extension', 'e')
            ->andWhere('c.status = :status')
            ->andWhere('e.status = :extensionStatus')
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->setParameter('extensionStatus', ExtensionStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->orderBy('RANDOM()')
            ->setMaxResults($maxResult)
            ->getQuery()
            ->getSingleColumnResult()
        ;
    }
}
