<?php

declare(strict_types=1);

namespace App\Enum\Market;

enum MarketPurchaseStatusEnum: string
{
    case PAYMENT_PENDING = 'payment_pending';

    case CARD_TRANSFERRED = 'card_transferred';

    case COMPLETED = 'completed';
    case FAILED = 'failed';

    case REFUND_PENDING = 'refund_pending';

    case REFUNDED = 'refunded';

    public function isUnsettled(): bool
    {
        return \in_array($this, [self::PAYMENT_PENDING, self::CARD_TRANSFERRED, self::REFUND_PENDING], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PAYMENT_PENDING => 'Paiement en vérification',
            self::CARD_TRANSFERRED => 'Versement vendeur en cours',
            self::COMPLETED => 'Terminée',
            self::FAILED => 'Échouée',
            self::REFUND_PENDING => 'Remboursement en cours',
            self::REFUNDED => 'Remboursée',
        };
    }
}
