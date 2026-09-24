<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecycleOperationCardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One distinct card debited in a recycle operation (composite PK). Same
 * quantity semantics as UserCard: quantity is the TOTAL of debited copies,
 * holoQuantity the holo sub-count included in it.
 */
#[ORM\Entity(repositoryClass: RecycleOperationCardRepository::class)]
class RecycleOperationCard
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'recycleOperationCards')]
        #[ORM\JoinColumn(nullable: false)]
        private RecycleOperation $recycleOperation,
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

    public function getRecycleOperation(): RecycleOperation
    {
        return $this->recycleOperation;
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
