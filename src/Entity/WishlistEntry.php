<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WishlistEntryRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * A card the player explicitly wishes for. It can only be created from a place
 * where the card is already visible to them (see WishlistService).
 */
#[ORM\Entity(repositoryClass: WishlistEntryRepository::class)]
#[ORM\UniqueConstraint(columns: ['player_id', 'card_id'])]
#[ORM\Index(columns: ['card_id'])]
class WishlistEntry
{
    use IdUuidTrait;
    use TimestampableEntity;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'discord_id', nullable: false, onDelete: 'CASCADE')]
        private DiscordUser $player,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Card $card,
    ) {
    }

    public function getPlayer(): DiscordUser
    {
        return $this->player;
    }

    public function getCard(): Card
    {
        return $this->card;
    }
}
