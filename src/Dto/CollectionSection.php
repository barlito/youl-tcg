<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One block of the collection grid: a universe (sorted by universe) or the
 * whole flattened result (any other sort, universe = null).
 */
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
