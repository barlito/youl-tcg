<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Market\MarketListingStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MarketListing> */
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

    /** @return array<string, array{normal: int, holo: int}> */
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

    /** @return list<MarketListing> */
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
     * @return list<MarketListing>
     */
    public function findActivePage(?DiscordUser $seller, ?DiscordUser $excludedSeller, ?Extension $extension, ?CardRarityEnum $rarity, ?bool $holo, string $sort, int $page, int $perPage, ?DiscordUser $wishOf = null): array
    {
        $queryBuilder = $this->activeQuery($seller, $excludedSeller, $extension, $rarity, $holo, $wishOf)
            ->addSelect('card', 'extension', 'seller')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
        ;

        match ($sort) {
            'price_asc' => $queryBuilder->orderBy('listing.price', 'ASC')->addOrderBy('listing.createdAt', 'DESC'),
            'price_desc' => $queryBuilder->orderBy('listing.price', 'DESC')->addOrderBy('listing.createdAt', 'DESC'),
            'date_asc' => $queryBuilder->orderBy('listing.createdAt', 'ASC'),
            default => $queryBuilder->orderBy('listing.createdAt', 'DESC'),
        };
        $queryBuilder->addOrderBy('listing.id', 'ASC');

        return array_values(iterator_to_array(new Paginator($queryBuilder->getQuery(), fetchJoinCollection: false)));
    }

    public function countActive(?DiscordUser $seller, ?DiscordUser $excludedSeller, ?Extension $extension, ?CardRarityEnum $rarity, ?bool $holo, ?DiscordUser $wishOf = null): int
    {
        return (int) $this->activeQuery($seller, $excludedSeller, $extension, $rarity, $holo, $wishOf)
            ->select('COUNT(listing.id)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /** @return list<Extension> */
    public function findExtensionsWithActiveListings(?DiscordUser $seller, ?DiscordUser $excludedSeller): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->activeQuery($seller, $excludedSeller, null, null, null)
            ->select('DISTINCT extension.id AS id')
            ->getQuery()
            ->getResult()
        ;

        return array_values($this->getEntityManager()->getRepository(Extension::class)->findBy(
            ['id' => array_column($rows, 'id')],
            ['name' => 'ASC'],
        ));
    }

    /** True when a published card has an ACTIVE listing: it is then visible in clear on /marche. */
    public function existsActiveForCard(Card $card): bool
    {
        return (int) $this->activeQuery(null, null, null, null, null)
            ->select('COUNT(listing.id)')
            ->andWhere('listing.card = :listedCard')
            ->setParameter('listedCard', $card)
            ->getQuery()
            ->getSingleScalarResult() > 0
        ;
    }

    /**
     * @param list<string> $cardIds
     *
     * @return array<string, true> card id => has an active listing
     */
    public function findActiveCardIds(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{id: mixed}> $rows */
        $rows = $this->activeQuery(null, null, null, null, null)
            ->select('DISTINCT card.id AS id')
            ->andWhere('card.id IN (:listedCards)')
            ->setParameter('listedCards', $cardIds)
            ->getQuery()
            ->getResult()
        ;

        return array_fill_keys(array_map(static fn (array $row): string => (string) $row['id'], $rows), true);
    }

    private function activeQuery(?DiscordUser $seller, ?DiscordUser $excludedSeller, ?Extension $extension, ?CardRarityEnum $rarity, ?bool $holo, ?DiscordUser $wishOf = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('listing')
            ->join('listing.card', 'card')
            ->join('card.extension', 'extension')
            ->join('listing.seller', 'seller')
            ->andWhere('listing.status = :active')
            ->andWhere('card.status = :cardStatus')
            ->andWhere('extension.status = :extensionStatus')
            ->setParameter('active', MarketListingStatusEnum::ACTIVE)
            ->setParameter('cardStatus', CardStatusEnum::PUBLISHED)
            ->setParameter('extensionStatus', ExtensionStatusEnum::PUBLISHED)
        ;

        if ($seller instanceof DiscordUser) {
            $queryBuilder->andWhere('listing.seller = :seller')->setParameter('seller', $seller);
        }

        if ($excludedSeller instanceof DiscordUser) {
            $queryBuilder->andWhere('listing.seller != :excluded')->setParameter('excluded', $excludedSeller);
        }

        if ($extension instanceof Extension) {
            $queryBuilder->andWhere('card.extension = :extension')->setParameter('extension', $extension);
        }

        if ($rarity instanceof CardRarityEnum) {
            $queryBuilder->andWhere('card.rarity = :rarity')->setParameter('rarity', $rarity);
        }

        if (null !== $holo) {
            $queryBuilder->andWhere('listing.holo = :holo')->setParameter('holo', $holo);
        }

        if ($wishOf instanceof DiscordUser) {
            $queryBuilder
                ->andWhere('EXISTS (SELECT 1 FROM App\Entity\WishlistEntry wish WHERE wish.player = :wishOf AND wish.card = card)
                    OR (EXISTS (SELECT 1 FROM App\Entity\WishlistUniverse watched WHERE watched.player = :wishOf AND watched.extension = card.extension)
                        AND NOT EXISTS (SELECT 1 FROM App\Entity\UserCard owned WHERE owned.discordUser = :wishOf AND owned.card = card AND (owned.quantity > 0 OR owned.holoQuantity > 0)))')
                ->setParameter('wishOf', $wishOf)
            ;
        }

        return $queryBuilder;
    }

    /** @return list<MarketListingStatusEnum> */
    private function engagedStatuses(): array
    {
        return array_values(array_filter(MarketListingStatusEnum::cases(), static fn (MarketListingStatusEnum $status): bool => $status->isEngaged()));
    }
}
