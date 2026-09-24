<?php

declare(strict_types=1);

namespace App\Exception\Trade;

/**
 * The trades feature flag is OFF: nothing is created, answered or cancelled,
 * pending offers stay frozen until it is switched back on.
 */
final class TradesClosedException extends TradeException
{
}
