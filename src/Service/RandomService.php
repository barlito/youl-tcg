<?php

declare(strict_types=1);

namespace App\Service;

class RandomService
{
    private ?int $seed = null;

    /**
     * Set the seed for random number generation.
     */
    public function setSeed(int $seed): void
    {
        $this->seed = $seed;
        mt_srand($seed);
    }

    /**
     * Get the current seed value.
     */
    public function getSeed(): ?int
    {
        return $this->seed;
    }

    /**
     * Generate a random integer between min and max (inclusive).
     */
    public function getRandomInt(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }
}
