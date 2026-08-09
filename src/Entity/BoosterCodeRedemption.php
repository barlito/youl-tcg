<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterCodeRedemptionRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Audit log of code redemptions. The unique (code, user) pair is what caps a
 * global code to one redemption per player — without it a single player could
 * drain a whole batch posted on Discord.
 */
#[ORM\Entity(repositoryClass: BoosterCodeRedemptionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_booster_code_redemption', columns: ['booster_code_id', 'discord_user_id'])]
class BoosterCodeRedemption
{
    use IdUuidTrait;
    use TimestampableEntity;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private BoosterCode $boosterCode,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $discordUser,
        #[ORM\Column]
        private \DateTimeImmutable $redeemedAt,
        /** Boosters credited, frozen here: the code's quantity may change later. */
        #[ORM\Column]
        private int $quantity,
    ) {
    }

    public function getBoosterCode(): BoosterCode
    {
        return $this->boosterCode;
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getRedeemedAt(): \DateTimeImmutable
    {
        return $this->redeemedAt;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
