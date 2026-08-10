<?php

declare(strict_types=1);

namespace App\Enum\Trade;

enum TradeOfferStatusEnum: string
{
    case PENDING = 'pending';

    case ACCEPTED = 'accepted';

    case REFUSED = 'refused';

    /** Withdrawn by the proposer while still pending. */
    case CANCELLED = 'cancelled';

    /** Became infeasible (the proposer no longer holds the offered copies). */
    case INVALIDATED = 'invalidated';

    public function isFinal(): bool
    {
        return self::PENDING !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::ACCEPTED => 'Acceptée',
            self::REFUSED => 'Refusée',
            self::CANCELLED => 'Annulée',
            self::INVALIDATED => 'Invalidée',
        };
    }
}
