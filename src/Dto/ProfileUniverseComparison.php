<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Extension;

/**
 * The compared set of one universe: every published card of the extension plus
 * the counters of the comparison. mysteryCount is the number of 1/1 tiles left
 * out of those counters.
 */
final readonly class ProfileUniverseComparison
{
    /**
     * @param list<ProfileCardComparison> $cards
     */
    public function __construct(
        public Extension $extension,
        public array $cards,
        public int $total,
        public int $common,
        public int $profileOnly,
        public int $visitorOnly,
        public int $missingBoth,
        public int $mysteryCount,
    ) {
    }
}
