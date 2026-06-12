<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Extension;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Extension>
 */
class ExtensionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Extension::class);
    }

    /**
     * @return list<array{extension: Extension, cardCount: int}>
     */
    public function findPublishedWithPublishedCardCount(): array
    {
        /** @var list<array{0: Extension, cardCount: string|int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e', 'COUNT(c.id) AS cardCount')
            ->leftJoin('e.cards', 'c', 'WITH', 'c.status = :cardStatus')
            ->andWhere('e.status = :status')
            ->setParameter('cardStatus', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->setParameter('status', ExtensionStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->groupBy('e.id')
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return array_map(
            static fn (array $row): array => ['extension' => $row[0], 'cardCount' => (int) $row['cardCount']],
            $rows,
        );
    }
}
