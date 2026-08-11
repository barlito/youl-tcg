<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The whole published catalogue compared between a visited profile and its
 * visitor, universe by universe, with the global counters of the filter bar.
 */
final readonly class ProfileComparison
{
    /**
     * @param list<ProfileUniverseComparison> $universes
     */
    public function __construct(
        public array $universes,
        public int $total,
        public int $common,
        public int $profileOnly,
        public int $visitorOnly,
        public int $missingBoth,
        public bool $isSelf,
    ) {
    }
}
