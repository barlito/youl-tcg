<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Collection\CollectionSortEnum;

/**
 * The collection grid once the url filters and sort are applied.
 */
final readonly class CollectionView
{
    /**
     * @param list<CollectionSection> $sections
     */
    public function __construct(
        public CollectionQuery $query,
        public array $sections,
        public int $resultCount,
    ) {
    }

    public function isGrouped(): bool
    {
        return CollectionSortEnum::UNIVERSE === $this->query->sort;
    }
}
