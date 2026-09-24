<?php

declare(strict_types=1);

namespace App\Exception\Trade;

/**
 * Raised by an acceptance when the proposer can no longer cover the offered
 * side: the offer has been switched to INVALIDATED (and that switch is
 * COMMITTED — the exception is thrown after the transaction, not inside it).
 */
final class TradeOfferInvalidatedException extends TradeException
{
}
