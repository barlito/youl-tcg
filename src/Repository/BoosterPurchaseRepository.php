<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoosterPurchase;
use App\Entity\DiscordUser;
use App\Enum\Booster\BoosterPurchaseStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoosterPurchase>
 */
class BoosterPurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoosterPurchase::class);
    }

    public function countSince(DiscordUser $discordUser, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('purchase')
            ->select('COUNT(purchase.id)')
            ->andWhere('purchase.discordUser = :discordUser')
            ->andWhere('purchase.requestedAt >= :since')
            ->andWhere('purchase.status IN (:statuses)')
            ->setParameter('discordUser', $discordUser)
            // Doctrine binds datetimes without converting their time zone
            ->setParameter('since', $since->setTimezone(new \DateTimeZone('UTC')))
            ->setParameter('statuses', [BoosterPurchaseStatusEnum::PENDING, BoosterPurchaseStatusEnum::COMPLETED])
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * @return list<BoosterPurchase>
     */
    public function findPending(?DiscordUser $discordUser = null): array
    {
        $queryBuilder = $this->createQueryBuilder('purchase')
            ->andWhere('purchase.status = :status')
            ->setParameter('status', BoosterPurchaseStatusEnum::PENDING)
            ->orderBy('purchase.requestedAt', 'ASC')
        ;

        if ($discordUser instanceof DiscordUser) {
            $queryBuilder->andWhere('purchase.discordUser = :discordUser')->setParameter('discordUser', $discordUser);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
