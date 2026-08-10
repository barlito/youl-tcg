<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RoleEnum;
use App\Repository\DiscordUserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: DiscordUserRepository::class)]
class DiscordUser implements UserInterface, \Stringable
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\Column(type: 'string', unique: true)]
    private string $discordId;

    #[ORM\Column(length: 255)]
    private string $username;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var Collection<int, UserCard>
     */
    #[ORM\OneToMany(targetEntity: UserCard::class, mappedBy: 'discordUser')]
    private Collection $userCards;

    /**
     * @var Collection<int, UserBooster>
     */
    #[ORM\OneToMany(targetEntity: UserBooster::class, mappedBy: 'discordUser')]
    private Collection $userBoosters;

    public function __construct()
    {
        $this->userCards = new ArrayCollection();
        $this->userBoosters = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->username;
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
     * @return Collection<int, UserCard>
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
     * Distinct cards owned, i.e. how many entries of the catalogue the player
     * has unlocked — not how many copies they hold.
     */
    public function getDistinctCardCount(): int
    {
        return $this->userCards->count();
    }

    /**
     * Every copy owned, holo included: what the player would count if they
     * laid their collection on the table. quantity is already that total —
     * holoQuantity is a sub-count of it, never an extra.
     */
    public function getCardCopyCount(): int
    {
        $total = 0;
        foreach ($this->userCards as $userCard) {
            $total += $userCard->getQuantity();
        }

        return $total;
    }

    /**
     * Unopened boosters still in the inventory, copies included.
     */
    public function getBoosterCopyCount(): int
    {
        $total = 0;
        foreach ($this->userBoosters as $userBooster) {
            $total += $userBooster->getQuantity();
        }

        return $total;
    }

    /**
     * @return Collection<int, UserBooster>
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

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->username);

        return $this->username;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = RoleEnum::ROLE_USER->value;

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }
}
