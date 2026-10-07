<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketListing;
use App\Entity\WishlistAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WishlistAlert> */
class WishlistAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistAlert::class);
    }

    /**
     * Raw ON CONFLICT: true only for the caller that really recorded the alert,
     * so two concurrent triggers never notify the same player twice.
     */
    public function insertIgnore(string $playerId, MarketListing $listing, \DateTimeImmutable $at): bool
    {
        $inserted = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO wishlist_alert (player_id, listing_id, created_at) VALUES (:player, :listing, :at) ON CONFLICT (player_id, listing_id) DO NOTHING',
            ['player' => $playerId, 'listing' => (string) $listing->getId(), 'at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );

        return 1 === (int) $inserted;
    }
}
