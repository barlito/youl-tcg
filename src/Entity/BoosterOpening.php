<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Entity\CardRarityEnum;
use App\Repository\BoosterOpeningRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * Audit log of a booster opening: who opened what, when, with which RNG seed
 * (the seed makes any draw reproducible) and which cards came out.
 */
#[ORM\Entity(repositoryClass: BoosterOpeningRepository::class)]
class BoosterOpening
{
    use IdUuidTrait;
    use TimestampableEntity;

    /**
     * @var Collection<int, BoosterOpeningCard>
     */
    #[ORM\OneToMany(targetEntity: BoosterOpeningCard::class, mappedBy: 'boosterOpening', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $boosterOpeningCards;

    public function __construct(#[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $discordUser, #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Booster $booster, #[ORM\Column(type: Types::BIGINT)]
        private int $seed, #[ORM\Column]
        private \DateTimeImmutable $openedAt)
    {
        $this->boosterOpeningCards = new ArrayCollection();
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getBooster(): Booster
    {
        return $this->booster;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    /**
     * @return Collection<int, BoosterOpeningCard>
     */
    public function getBoosterOpeningCards(): Collection
    {
        return $this->boosterOpeningCards;
    }

    public function addBoosterOpeningCard(BoosterOpeningCard $boosterOpeningCard): static
    {
        if (!$this->boosterOpeningCards->contains($boosterOpeningCard)) {
            $this->boosterOpeningCards->add($boosterOpeningCard);
        }

        return $this;
    }

    /**
     * Drawn cards, rarest first then alphabetically — the reading order of the
     * admin detail screen.
     *
     * @return list<BoosterOpeningCard>
     */
    public function getDrawnCards(): array
    {
        $drawnCards = array_values($this->boosterOpeningCards->toArray());

        usort($drawnCards, static fn (BoosterOpeningCard $left, BoosterOpeningCard $right): int => CardRarityEnum::compareRarestFirst($left->getCard()->getRarity(), $right->getCard()->getRarity())
            ?: strcmp($left->getCard()->getName(), $right->getCard()->getName()));

        return $drawnCards;
    }

    /**
     * Copies drawn, duplicates included: the booster's actual card count.
     */
    public function getDrawnCardCount(): int
    {
        return $this->sumDrawnCards(static fn (BoosterOpeningCard $drawnCard): int => $drawnCard->getQuantity());
    }

    public function getHoloCount(): int
    {
        return $this->sumDrawnCards(static fn (BoosterOpeningCard $drawnCard): int => $drawnCard->getHoloQuantity());
    }

    /**
     * Rarest card of the draw, null when the opening has no card row.
     */
    public function getBestRarity(): ?CardRarityEnum
    {
        $best = null;

        foreach ($this->boosterOpeningCards as $drawnCard) {
            $rarity = $drawnCard->getCard()->getRarity();

            if (!$best instanceof CardRarityEnum || $rarity->rank() > $best->rank()) {
                $best = $rarity;
            }
        }

        return $best;
    }

    /**
     * @param callable(BoosterOpeningCard): int $counter
     */
    private function sumDrawnCards(callable $counter): int
    {
        $total = 0;

        foreach ($this->boosterOpeningCards as $drawnCard) {
            $total += $counter($drawnCard);
        }

        return $total;
    }
}
