<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WishlistUniverseRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * « Warn me about every card I am missing in this universe »: the player never
 * learns which cards those are, the match is done server-side.
 */
#[ORM\Entity(repositoryClass: WishlistUniverseRepository::class)]
#[ORM\UniqueConstraint(columns: ['player_id', 'extension_id'])]
#[ORM\Index(columns: ['extension_id'])]
class WishlistUniverse
{
    use IdUuidTrait;
    use TimestampableEntity;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'discord_id', nullable: false, onDelete: 'CASCADE')]
        private DiscordUser $player,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Extension $extension,
    ) {
    }

    public function getPlayer(): DiscordUser
    {
        return $this->player;
    }

    public function getExtension(): Extension
    {
        return $this->extension;
    }
}
