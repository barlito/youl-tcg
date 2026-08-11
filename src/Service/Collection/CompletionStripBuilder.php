<?php

declare(strict_types=1);

namespace App\Service\Collection;

use App\Dto\ProfileUniverseComparison;
use App\Entity\Extension;
use App\Repository\CardRepository;

/**
 * The « complétion par univers » strip, shared by « Ma collection » and the
 * public player profiles: one tile per universe, its owner's completion, and
 * the artwork used as a background when the extension has no uploaded image.
 */
final readonly class CompletionStripBuilder
{
    public function __construct(
        private CardRepository $cardRepository,
    ) {
    }

    /**
     * @param list<ProfileUniverseComparison> $universes
     *
     * @return list<array{extension: Extension, total: int, owned: int, percentage: int, coverImage: string|null}>
     */
    public function build(array $universes): array
    {
        $coverImages = $this->cardRepository->findCoverImageNamesByExtension();

        return array_map(static fn (ProfileUniverseComparison $universe): array => [
            'extension' => $universe->extension,
            'total' => $universe->total,
            'owned' => $universe->owned(),
            'percentage' => $universe->completionPct(),
            'coverImage' => $coverImages[(string) $universe->extension->getId()] ?? null,
        ], $universes);
    }
}
