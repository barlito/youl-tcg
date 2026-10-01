<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Card;

/**
 * One card of a recycle selection: how many normal and holo copies the player
 * wants to trade in. Quantities are kept separate on purpose (unlike the
 * UserCard total/sub-count pair) so the debit math cannot mix them up.
 */
final readonly class RecycleSelectionLine
{
    public function __construct(
        public Card $card,
        public int $normalQuantity,
        public int $holoQuantity,
    ) {
    }

    public function getTotalQuantity(): int
    {
        return $this->normalQuantity + $this->holoQuantity;
    }

    public function getPoints(): int
    {
        $rarity = $this->card->getRarity();

        return $this->normalQuantity * $rarity->recyclePoints()
            + $this->holoQuantity * $rarity->holoRecyclePoints();
    }

    /**
     * Value of the least valuable copy of the line (0 for an empty line).
     */
    public function getCheapestCopyPoints(): int
    {
        $rarity = $this->card->getRarity();
        $values = [];
        if ($this->normalQuantity > 0) {
            $values[] = $rarity->recyclePoints();
        }
        if ($this->holoQuantity > 0) {
            $values[] = $rarity->holoRecyclePoints();
        }

        return [] === $values ? 0 : min($values);
    }
}
