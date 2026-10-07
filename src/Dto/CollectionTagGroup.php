<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One tag family of the collection filter chips (Personnages, Familles, Traits…).
 */
final readonly class CollectionTagGroup
{
    /**
     * @param list<array{tag: string, label: string, count: int}> $tags
     */
    public function __construct(
        public string $family,
        public string $label,
        public array $tags,
    ) {
    }
}
