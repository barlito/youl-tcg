<?php

namespace App\Entity;

use App\Repository\DiscordUserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscordUserRepository::class)]
class DiscordUser
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', unique: true)]
    private string $discordId;

    #[ORM\Column(length: 255)]
    private string $username;

//    #[ORM\OneToMany(mappedBy: 'discordUser', targetEntity: UserCard::class)]
//    private Collection $userCards;
//
//    #[ORM\OneToMany(mappedBy: 'discordUser', targetEntity: UserBooster::class)]
//    private Collection $userBoosters;

    public function __construct()
    {
        $this->userCards = new ArrayCollection();
        $this->userBoosters = new ArrayCollection();
    }

    public function getDiscordId(): string
    {
        return $this->discordId;
    }

    public function setDiscordId(string $discordId): static
    {
        $this->discordId = $discordId;

        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * @return Collection<UserCard>
     */
    public function getUserCards(): Collection
    {
        return $this->userCards;
    }

    public function addUserCard(UserCard $userCard): static
    {
        if (!$this->userCards->contains($userCard)) {
            $this->userCards->add($userCard);
            $userCard->setDiscordUser($this);
        }

        return $this;
    }

    /**
     * @return Collection<UserBooster>
     */
    public function getUserBoosters(): Collection
    {
        return $this->userBoosters;
    }

    public function addUserBooster(UserBooster $userBooster): static
    {
        if (!$this->userBoosters->contains($userBooster)) {
            $this->userBoosters->add($userBooster);
            $userBooster->setDiscordUser($this);
        }

        return $this;
    }
}
