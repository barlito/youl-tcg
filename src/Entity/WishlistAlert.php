<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WishlistAlertRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (player, listing) alert sent: the dedup ledger of the market
 * alerts (a listing never alerts the same player twice) and the stats source.
 */
#[ORM\Entity(repositoryClass: WishlistAlertRepository::class)]
class WishlistAlert
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'discord_id', nullable: false, onDelete: 'CASCADE')]
        private DiscordUser $player,
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MarketListing $listing,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getPlayer(): DiscordUser
    {
        return $this->player;
    }

    public function getListing(): MarketListing
    {
        return $this->listing;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
