<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\IdUuidTrait;
use App\Repository\BoosterClaimRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Audit log of daily free booster claims. The daily quota is enforced by
 * counting claims since the last midnight (Europe/Paris), so there is no
 * mutable counter to reset.
 */
#[ORM\Entity(repositoryClass: BoosterClaimRepository::class)]
class BoosterClaim
{
    use IdUuidTrait;
    use TimestampableEntity;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $discordUser,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Booster $booster,
        #[ORM\Column]
        private \DateTimeImmutable $claimedAt,
    ) {
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getBooster(): Booster
    {
        return $this->booster;
    }

    public function getClaimedAt(): \DateTimeImmutable
    {
        return $this->claimedAt;
    }
}
