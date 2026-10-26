<?php

declare(strict_types=1);

namespace App\Service\Collection;

use App\Dto\ProfileUniverseComparison;
use App\Entity\Extension;

// « Par univers » strip of the collection and player profiles; never a card artwork as background (masking rule)
final readonly class CompletionStripBuilder
{
    /**
     * @param list<ProfileUniverseComparison> $universes
     *
     * @return list<array{extension: Extension, total: int, owned: int, percentage: int}>
     */
    public function build(array $universes): array
    {
        return array_map(static fn (ProfileUniverseComparison $universe): array => [
            'extension' => $universe->extension,
            'total' => $universe->total,
            'owned' => $universe->owned(),
            'percentage' => $universe->completionPct(),
        ], $universes);
    }
}
