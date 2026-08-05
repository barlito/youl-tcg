<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterClaimRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Audit log of daily free booster claims. The daily quota is enforced by
 * counting claims since the last midnight (Europe/Paris), so there is no
 * mutable counter to reset.
 */
#[ORM\Entity(repositoryClass: BoosterClaimRepository::class)]
// The daily quota is a COUNT on (user, claimed_at >= midnight) executed on
// every hub render (BoosterClaimRepository::countSince): index the pair.
#[ORM\Index(columns: ['discord_user_id', 'claimed_at'])]
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
