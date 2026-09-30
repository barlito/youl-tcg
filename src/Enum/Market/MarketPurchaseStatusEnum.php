<?php

declare(strict_types=1);

namespace App\Enum\Market;

enum MarketPurchaseStatusEnum: string
{
    /** Buyer's payment sent to the bank, outcome not confirmed yet. */
    case PAYMENT_PENDING = 'payment_pending';

    /** Card moved to the buyer, the seller's payout is still to be confirmed. */
    case CARD_TRANSFERRED = 'card_transferred';

    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /** Paid but the card could not move: the buyer's refund is still to be confirmed. */
    case REFUND_PENDING = 'refund_pending';

    case REFUNDED = 'refunded';

    /** Still waiting for the coin: the reconciliation resumes these. */
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
