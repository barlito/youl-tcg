<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecycleOperationRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Audit log of a recycle operation: who traded which duplicate copies, for how
 * many points, against which booster. The card rows carry the exact debited
 * copies so the operation can be reconstructed.
 */
#[ORM\Entity(repositoryClass: RecycleOperationRepository::class)]
class RecycleOperation
{
    use IdUuidTrait;
    use TimestampableEntity;

    /**
     * @var Collection<int, RecycleOperationCard>
     */
    #[ORM\OneToMany(targetEntity: RecycleOperationCard::class, mappedBy: 'recycleOperation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $recycleOperationCards;

    public function __construct(#[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $discordUser, #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Booster $booster, #[ORM\Column]
        private int $points, #[ORM\Column]
        private \DateTimeImmutable $recycledAt)
    {
        $this->recycleOperationCards = new ArrayCollection();
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getBooster(): Booster
    {
        return $this->booster;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function getRecycledAt(): \DateTimeImmutable
    {
        return $this->recycledAt;
    }

    /**
     * @return Collection<int, RecycleOperationCard>
     */
    public function getRecycleOperationCards(): Collection
    {
        return $this->recycleOperationCards;
    }

    public function addRecycleOperationCard(RecycleOperationCard $recycleOperationCard): static
    {
        if (!$this->recycleOperationCards->contains($recycleOperationCard)) {
            $this->recycleOperationCards->add($recycleOperationCard);
        }

        return $this;
    }

    /**
     * Copies recycled, holo included: the operation's actual card count.
     */
    public function getRecycledCardCount(): int
    {
        $total = 0;

        foreach ($this->recycleOperationCards as $recycledCard) {
            $total += $recycledCard->getQuantity();
        }

        return $total;
    }
}
