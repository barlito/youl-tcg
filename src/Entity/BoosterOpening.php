<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterOpeningRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: BoosterOpeningRepository::class)]
class BoosterOpening
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\ManyToOne(inversedBy: 'boosterOpenings')]
    #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
    private DiscordUser $discordUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Booster $booster;

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(type: 'bigint')]
    private int $seed;

    #[ORM\OneToMany(targetEntity: BoosterOpeningCard::class, mappedBy: 'boosterOpening', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $boosterOpeningCards;

    public function __construct()
    {
        $this->openedAt = new \DateTimeImmutable();
        $this->boosterOpeningCards = new ArrayCollection();
    }

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

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(\DateTimeImmutable $openedAt): static
    {
        $this->openedAt = $openedAt;

        return $this;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function setSeed(int $seed): static
    {
        $this->seed = $seed;

        return $this;
    }

    /**
     * @return Collection<BoosterOpeningCard>
     */
    public function getBoosterOpeningCards(): Collection
    {
        return $this->boosterOpeningCards;
    }

    public function addBoosterOpeningCard(BoosterOpeningCard $boosterOpeningCard): static
    {
        if (!$this->boosterOpeningCards->contains($boosterOpeningCard)) {
            $this->boosterOpeningCards->add($boosterOpeningCard);
            $boosterOpeningCard->setBoosterOpening($this);
        }

        return $this;
    }

    public function removeBoosterOpeningCard(BoosterOpeningCard $boosterOpeningCard): static
    {
        $this->boosterOpeningCards->removeElement($boosterOpeningCard);

        return $this;
    }
}
