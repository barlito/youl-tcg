<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserCardRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: UserCardRepository::class)]
class UserCard
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'userCards')]
    #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
    private DiscordUser $discordUser;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Card $card;

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

    public function getCard(): Card
    {
        return $this->card;
    }

    public function setCard(Card $card): static
    {
        $this->card = $card;

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
