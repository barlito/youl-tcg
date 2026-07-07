<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /**
     * Extension ids that have at least one published card, i.e. extensions a
     * booster can actually draw from.
     *
     * @return list<string>
     */
    public function findExtensionIdsWithPublishedCards(): array
    {
        return array_map(static fn (mixed $id): string => (string) $id, array_values($this->createQueryBuilder('c')
            ->select('IDENTITY(c.extension) AS extensionId')
            ->andWhere('c.status = :status')
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->distinct()
            ->getQuery()
            ->getSingleColumnResult()));
    }

    /**
     * Published cards of an extension — the "set contents" shown in the opening
     * aside. Ordering by rarity is handled in the component (CardRarityEnum rank).
     *
     * @return list<Card>
     */
    public function findPublishedByExtension(Extension $extension): array
    {
        return array_values($this->createQueryBuilder('c')
            ->andWhere('c.extension = :extension')
            ->andWhere('c.status = :status')
            ->setParameter('extension', $extension)
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * @return list<mixed> published card ids
     */
    public function findRandomCardId(int $maxResult): array
    {
        return array_values($this->createQueryBuilder('c')
            ->select('c.id')
            ->join('c.extension', 'e')
            ->andWhere('c.status = :status')
            ->andWhere('e.status = :extensionStatus')
            ->setParameter('status', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->setParameter('extensionStatus', ExtensionStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->orderBy('RANDOM()')
            ->setMaxResults($maxResult)
            ->getQuery()
            ->getSingleColumnResult());
    }
}
