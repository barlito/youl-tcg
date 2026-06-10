<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterOpeningCardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One distinct card drawn in a booster opening. Duplicates are aggregated:
 * a single row per card with quantity / holoQuantity counters (composite PK).
 */
#[ORM\Entity(repositoryClass: BoosterOpeningCardRepository::class)]
class BoosterOpeningCard
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'boosterOpeningCards')]
        #[ORM\JoinColumn(nullable: false)]
        private BoosterOpening $boosterOpening,
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Card $card,
        #[ORM\Column]
        private int $quantity,
        #[ORM\Column(options: ['default' => 0])]
        private int $holoQuantity = 0,
    ) {
    }

    public function getBoosterOpening(): BoosterOpening
    {
        return $this->boosterOpening;
    }

    public function getCard(): Card
    {
        return $this->card;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getHoloQuantity(): int
    {
        return $this->holoQuantity;
    }
}
