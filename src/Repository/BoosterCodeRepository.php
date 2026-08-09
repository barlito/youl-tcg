<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterCode>
 */
class BoosterCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterCode::class);
    }

    /**
     * Pessimistic write lock: the redemption is a check-then-increment on
     * `uses`, so concurrent redemptions of the same code must serialize or a
     * global code would overshoot its maxUses. Requires an active transaction.
     */
    public function findOneForUpdate(string $code): ?BoosterCode
    {
        return $this->createQueryBuilder('boosterCode')
            ->andWhere('boosterCode.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;
    }

    /**
     * @return list<BoosterCode>
     */
    public function findByBatch(string $batchLabel): array
    {
        return $this->createQueryBuilder('boosterCode')
            ->andWhere('boosterCode.batchLabel = :batchLabel')
            ->setParameter('batchLabel', $batchLabel)
            ->orderBy('boosterCode.code', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Codes of the given list that already exist, so a generated batch can be
     * rerolled before hitting the unique index.
     *
     * @param list<string> $codes
     *
     * @return list<string>
     */
    public function findExistingCodes(array $codes): array
    {
        if ([] === $codes) {
            return [];
        }

        return array_column(
            $this->createQueryBuilder('boosterCode')
                ->select('boosterCode.code')
                ->andWhere('boosterCode.code IN (:codes)')
                ->setParameter('codes', $codes)
                ->getQuery()
                ->getScalarResult(),
            'code',
        );
    }
}
