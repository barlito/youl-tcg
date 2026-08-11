<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Extension;

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
    ) {
    }

    /**
     * Distinct published cards the visited profile owns — the numerator of the
     * completion bar and the sum of the strip tiles.
     */
    public function owned(): int
    {
        return $this->common + $this->profileOnly;
    }

    public function completionPct(): int
    {
        return $this->total > 0 ? (int) round($this->owned() / $this->total * 100) : 0;
    }

    /**
     * Resolves a universe filter against the comparison already built: no extra
     * Doctrine lookup, and the caller turns a null into its own 404.
     */
    public function extensionBySlug(string $slug): ?Extension
    {
        return $this->findExtension(static fn (Extension $extension): bool => $extension->getSlug() === $slug);
    }

    public function extensionById(string $id): ?Extension
    {
        return $this->findExtension(static fn (Extension $extension): bool => ((string) $extension->getId()) === $id);
    }

    /**
     * @param callable(Extension): bool $matches
     */
    private function findExtension(callable $matches): ?Extension
    {
        foreach ($this->universes as $universe) {
            if ($matches($universe->extension)) {
                return $universe->extension;
            }
        }

        return null;
    }
}
