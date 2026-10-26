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

    /**
     * @return list<MarketPurchase>
     */
    public function findRecentFor(DiscordUser $discordUser, int $limit): array
    {
        return array_values($this->createQueryBuilder('purchase')
            ->join('purchase.listing', 'listing')
            ->addSelect('listing')
            ->join('listing.card', 'card')
            ->addSelect('card')
            ->join('purchase.buyer', 'buyer')
            ->addSelect('buyer')
            ->join('purchase.seller', 'seller')
            ->addSelect('seller')
            ->andWhere('purchase.buyer = :user OR purchase.seller = :user')
            ->setParameter('user', $discordUser)
            ->orderBy('purchase.requestedAt', 'DESC')
            ->addOrderBy('purchase.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /**
     * Last concluded sale price per card and finish, in ONE query (correlated MAX on the request date).
     *
     * @param list<string> $cardIds
     *
     * @return array<string, int> "<card id>|normal" or "<card id>|holo" => whole coins
     */
    public function findLastSalePrices(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{cardId: mixed, holo: bool, price: int|string}> $rows */
        $rows = $this->createQueryBuilder('purchase')
            ->select('IDENTITY(listing.card) AS cardId', 'listing.holo AS holo', 'purchase.price AS price')
            ->join('purchase.listing', 'listing')
            ->andWhere('listing.card IN (:cards)')
            ->andWhere('purchase.status IN (:sold)')
            ->andWhere('purchase.requestedAt = (SELECT MAX(last.requestedAt) FROM App\Entity\MarketPurchase last JOIN last.listing lastListing
                WHERE lastListing.card = listing.card AND lastListing.holo = listing.holo AND last.status IN (:sold))')
            ->setParameter('cards', $cardIds)
            ->setParameter('sold', [MarketPurchaseStatusEnum::CARD_TRANSFERRED, MarketPurchaseStatusEnum::COMPLETED])
            ->getQuery()
            ->getResult()
        ;

        $prices = [];
        foreach ($rows as $row) {
            $prices[$row['cardId'] . '|' . ($row['holo'] ? 'holo' : 'normal')] = (int) $row['price'];
        }

        return $prices;
    }
}
