<?php

declare(strict_types=1);

namespace App\Dto\Admin;

final readonly class CoinAlerts
{
    public function __construct(
        public int $pendingBoosterPurchases,
        public int $failedBoosterPurchases,
        public int $pendingRewards,
        public int $failedRewards,
        public int $paymentPendingSales,
        public int $payoutPendingSales,
        public int $refundPendingSales,
    ) {
    }

    public function total(): int
    {
        return $this->pendingBoosterPurchases + $this->failedBoosterPurchases + $this->pendingRewards
            + $this->failedRewards + $this->paymentPendingSales + $this->payoutPendingSales + $this->refundPendingSales;
    }
}
