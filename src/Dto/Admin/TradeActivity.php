<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * Trade offers of the period, per Europe/Paris day: created on their
 * createdAt day, accepted/refused on their resolvedAt day.
 */
final readonly class TradeActivity
{
    /**
     * @param array<string, int> $createdPerDay  day => offers created
     * @param array<string, int> $acceptedPerDay day => offers accepted
     * @param array<string, int> $refusedPerDay  day => offers refused
     */
    public function __construct(
        public array $createdPerDay,
        public array $acceptedPerDay,
        public array $refusedPerDay,
    ) {
    }

    public function created(): int
    {
        return array_sum($this->createdPerDay);
    }

    public function accepted(): int
    {
        return array_sum($this->acceptedPerDay);
    }

    public function refused(): int
    {
        return array_sum($this->refusedPerDay);
    }

    /**
     * Accepted share of the offers answered in the period (cancelled and
     * invalidated ones got no answer). Null when nothing was answered.
     */
    public function acceptanceRate(): ?float
    {
        $answered = $this->accepted() + $this->refused();

        return $answered > 0 ? round($this->accepted() / $answered * 100, 2) : null;
    }
}
