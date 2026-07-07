<?php

declare(strict_types=1);

namespace App\Service\Random;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seedable RNG wrapper. Seeding makes a draw fully reproducible (the seed is
 * stored in BoosterOpening for auditing), without touching PHP's global
 * mt_srand() state.
 */
final class RandomService
{
    private ?Randomizer $randomizer = null;

    private ?Randomizer $sideRandomizer = null;

    private ?int $seed = null;

    public function seed(int $seed): void
    {
        $this->seed = $seed;
        $this->randomizer = new Randomizer(new Mt19937($seed));
        $this->sideRandomizer = new Randomizer(new Mt19937(crc32($seed . '-side')));
    }

    public function getSeed(): ?int
    {
        return $this->seed;
    }

    public function getInt(int $min, int $max): int
    {
        $this->randomizer ??= new Randomizer(new Mt19937());

        return $this->randomizer->getInt($min, $max);
    }

    /**
     * Draws from a SIDE stream derived from the seed. Rolls that depend on
     * concurrent state (e.g. the replacement draw for a unique card claimed by
     * another opening) must use this stream: consuming it never advances the
     * main stream, so replaying the stored seed still reproduces the main draw
     * whatever happened concurrently.
     */
    public function getSideInt(int $min, int $max): int
    {
        $this->sideRandomizer ??= new Randomizer(new Mt19937());

        return $this->sideRandomizer->getInt($min, $max);
    }
}
