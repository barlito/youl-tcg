<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\DiscordUser;

final readonly class TradeMatchScore
{
    public function __construct(
        public DiscordUser $player,
        public int $theyHaveForMe,
        public int $iHaveForThem,
    ) {
    }

    public function total(): int
    {
        return $this->theyHaveForMe + $this->iHaveForThem;
    }
}
