<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FusionOperationRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Audit log of a duplicate fusion: who turned how many normal copies of which
 * card into holo copies. One row per operation (one card, N fusions).
 */
#[ORM\Entity(repositoryClass: FusionOperationRepository::class)]
class FusionOperation
{
    use IdUuidTrait;
    use TimestampableEntity;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $discordUser,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Card $card,
        #[ORM\Column]
        private int $fusionCount,
        #[ORM\Column]
        private int $copiesConsumed,
        #[ORM\Column]
        private int $holosCreated,
        #[ORM\Column]
        private \DateTimeImmutable $fusedAt,
    ) {
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getCard(): Card
    {
        return $this->card;
    }

    public function getFusionCount(): int
    {
        return $this->fusionCount;
    }

    public function getCopiesConsumed(): int
    {
        return $this->copiesConsumed;
    }

    public function getHolosCreated(): int
    {
        return $this->holosCreated;
    }

    public function getFusedAt(): \DateTimeImmutable
    {
        return $this->fusedAt;
    }
}
