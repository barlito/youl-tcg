<?php

declare(strict_types=1);

namespace App\Dto;

// One block of the collection grid: a universe, or the flattened result (universe = null)
final readonly class CollectionSection
{
    /**
     * @param list<ProfileCardComparison> $items
     */
    public function __construct(
        public ?ProfileUniverseComparison $universe,
        public array $items,
    ) {
    }
}
