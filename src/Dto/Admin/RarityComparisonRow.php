<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * Observed vs expected share (in %) of one bucket of drawn cards.
 */
final readonly class RarityComparisonRow
{
    public function __construct(
        public string $key,
        public string $label,
        public int $observedCount,
        public float $observedShare,
        public float $expectedShare,
    ) {
    }

    /**
     * Observed minus expected, in percentage points.
     */
    public function gap(): float
    {
        return round($this->observedShare - $this->expectedShare, 2);
    }
}
