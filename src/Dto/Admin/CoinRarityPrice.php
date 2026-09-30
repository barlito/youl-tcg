<?php

declare(strict_types=1);

namespace App\Dto\Admin;

final readonly class CoinRarityPrice
{
    public function __construct(
        public string $key,
        public string $label,
        public int $sales,
        public float $averagePrice,
    ) {
    }
}
