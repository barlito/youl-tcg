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

    private ?int $seed = null;

    public function seed(int $seed): void
    {
        $this->seed = $seed;
        $this->randomizer = new Randomizer(new Mt19937($seed));
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
}
