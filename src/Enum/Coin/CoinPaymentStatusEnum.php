<?php

declare(strict_types=1);

namespace App\Enum\Coin;

enum CoinPaymentStatusEnum
{
    /** The coin recorded the transaction. */
    case PAID;

    /** The coin refused for good (4xx): nothing was debited. */
    case REFUSED;

    /** The coin could not be reached before anything was sent: nothing was debited. */
    case UNAVAILABLE;

    /** The debit was sent but its outcome is unknown (timeout, 5xx): it may have gone through. */
    case UNCERTAIN;

    /** Lookup only: the coin has no transaction for this identifier. */
    case NOT_FOUND;
}
