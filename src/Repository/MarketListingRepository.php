<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordUser;
use App\Entity\MarketListing;
use App\Enum\Market\MarketListingStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketListing>
 */
class MarketListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketListing::class);
    }

    // requires an active transaction; the caller must refresh() an already-hydrated entity
    public function findOneForUpdate(string $id): ?MarketListing
    {
        return $this->createQueryBuilder('listing')
            ->andWhere('listing.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;
    }

    /**
     * Listings that still hold a copy, the quota being counted on them.
     */
    public function countEngagedBySeller(DiscordUser $seller): int
    {
        return (int) $this->createQueryBuilder('listing')
            ->select('COUNT(listing.id)')
            ->andWhere('listing.seller = :seller')
            ->andWhere('listing.status IN (:statuses)')
            ->setParameter('seller', $seller)
            ->setParameter('statuses', $this->engagedStatuses())
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Copies a seller has engaged in listings, per card: one half of the reservation ledger (see EngagedCopies).
     *
     * @return array<string, array{normal: int, holo: int}> card id => reserved copies
     */
    public function sumReservedQuantities(DiscordUser $seller): array
    {
        /** @var list<array{cardId: mixed, holo: bool, total: string|int}> $rows */
        $rows = $this->createQueryBuilder('listing')
            ->select('IDENTITY(listing.card) AS cardId', 'listing.holo AS holo', 'COUNT(listing.id) AS total')
            ->andWhere('listing.seller = :seller')
            ->andWhere('listing.status IN (:statuses)')
            ->setParameter('seller', $seller)
            ->setParameter('statuses', $this->engagedStatuses())
            ->groupBy('listing.card', 'listing.holo')
            ->getQuery()
            ->getResult()
        ;

        $reserved = [];
        foreach ($rows as $row) {
            $cardId = (string) $row['cardId'];
            $reserved[$cardId] ??= ['normal' => 0, 'holo' => 0];
            $reserved[$cardId][$row['holo'] ? 'holo' : 'normal'] += (int) $row['total'];
        }

        return $reserved;
    }

    /**
     * @return list<MarketListing>
     */
    public function findEngagedBySeller(DiscordUser $seller): array
    {
        return array_values($this->createQueryBuilder('listing')
            ->andWhere('listing.seller = :seller')
            ->andWhere('listing.status IN (:statuses)')
            ->setParameter('seller', $seller)
            ->setParameter('statuses', $this->engagedStatuses())
            ->orderBy('listing.createdAt', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * @return list<MarketListingStatusEnum>
     */
    private function engagedStatuses(): array
    {
        return array_values(array_filter(MarketListingStatusEnum::cases(), static fn (MarketListingStatusEnum $status): bool => $status->isEngaged()));
    }
}
