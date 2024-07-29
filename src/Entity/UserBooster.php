<?php

namespace App\Entity;

use App\Repository\UserBoosterRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: UserBoosterRepository::class)]
class UserBooster
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'userBoosters')]
    #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
    private DiscordUser $discordUser;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Booster $booster;

    #[ORM\Column]
    private int $quantity = 0;

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function setDiscordUser(DiscordUser $discordUser): static
    {
        $this->discordUser = $discordUser;

        return $this;
    }

    public function getBooster(): Booster
    {
        return $this->booster;
    }

    public function setBooster(Booster $booster): static
    {
        $this->booster = $booster;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }
}
