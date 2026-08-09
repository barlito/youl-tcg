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
     * The "next universe" teaser tile; draft extensions qualify (the whole
     * point is teasing a universe BEFORE it is published).
     */
    public function findUpcoming(): ?Extension
    {
        return $this->findOneBy(['upcoming' => true]);
    }

    /**
     * At most one upcoming extension: called after saving a flagged one.
     */
    public function clearUpcomingExcept(Extension $extension): void
    {
        $this->createQueryBuilder('e')
            ->update()
            ->set('e.upcoming', ':off')
            ->andWhere('e.upcoming = true')
            ->andWhere('e.id != :kept')
            ->setParameter('off', false)
            ->setParameter('kept', $extension->getId())
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * hasClaimableBooster drives the LIVE badge on the universe tiles: an
     * universe is "live" only when at least one of its boosters is claimable.
     *
     * @return list<array{extension: Extension, cardCount: int, hasClaimableBooster: bool}>
     */
    public function findPublishedWithPublishedCardCount(): array
    {
        // both joins fan out the rows, hence the DISTINCT counts
        /** @var list<array{0: Extension, cardCount: string|int, claimableBoosterCount: string|int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e', 'COUNT(DISTINCT c.id) AS cardCount', 'COUNT(DISTINCT b.id) AS claimableBoosterCount')
            ->leftJoin('e.cards', 'c', 'WITH', 'c.status = :cardStatus')
            ->leftJoin('e.boosters', 'b', 'WITH', 'b.claimable = true')
            ->andWhere('e.status = :status')
            ->setParameter('cardStatus', CardStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->setParameter('status', ExtensionStatusEnum::PUBLISHED->value, ParameterType::INTEGER)
            ->groupBy('e.id')
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return array_map(
            static fn (array $row): array => [
                'extension' => $row[0],
                'cardCount' => (int) $row['cardCount'],
                'hasClaimableBooster' => (int) $row['claimableBoosterCount'] > 0,
            ],
            $rows,
        );
    }
}
