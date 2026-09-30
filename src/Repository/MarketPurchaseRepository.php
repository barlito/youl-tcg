<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\MarketPurchase;
use App\Enum\Market\MarketPurchaseStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MarketPurchase> */
class MarketPurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketPurchase::class);
    }

    /** @return list<MarketPurchase> */
    public function findUnsettled(?DiscordUser $discordUser = null): array
    {
        $queryBuilder = $this->createQueryBuilder('purchase')
            ->andWhere('purchase.status IN (:statuses)')
            ->setParameter('statuses', array_values(array_filter(MarketPurchaseStatusEnum::cases(), static fn (MarketPurchaseStatusEnum $status): bool => $status->isUnsettled())))
            ->orderBy('purchase.requestedAt', 'ASC')
        ;

        if ($discordUser instanceof DiscordUser) {
            $queryBuilder->andWhere('purchase.buyer = :user OR purchase.seller = :user')->setParameter('user', $discordUser);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
