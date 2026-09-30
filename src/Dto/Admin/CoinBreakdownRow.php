<?php

declare(strict_types=1);

namespace App\Dto\Admin;

final readonly class CoinBreakdownRow
{
    public function __construct(
        public string $label,
        public int $count,
        public int $coins,
    ) {
    }
}
