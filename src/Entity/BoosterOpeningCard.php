<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterOpeningCardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BoosterOpeningCardRepository::class)]
class BoosterOpeningCard
{
    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'boosterOpeningCards')]
    #[ORM\JoinColumn(nullable: false)]
    private BoosterOpening $boosterOpening;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Card $card;

    #[ORM\Column]
    private int $quantity = 1;

    public function getBoosterOpening(): BoosterOpening
    {
        return $this->boosterOpening;
    }

    public function setBoosterOpening(BoosterOpening $boosterOpening): static
    {
        $this->boosterOpening = $boosterOpening;

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
