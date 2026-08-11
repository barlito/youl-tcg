<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Extension;

/**
 * The compared set of one universe: every published card of the extension plus
 * the counters of the comparison.
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
    ) {
    }

    /**
     * What the visited profile owns in this universe: the cards shared with the
     * visitor plus the ones only they hold. On your own page profileOnly is
     * always 0, so the formula holds there too.
     */
    public function owned(): int
    {
        return $this->common + $this->profileOnly;
    }

    public function completionPct(): int
    {
        return $this->total > 0 ? (int) round($this->owned() / $this->total * 100) : 0;
    }
}
