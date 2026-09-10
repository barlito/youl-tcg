<?php

declare(strict_types=1);

namespace App\Exception\Trade;

/**
 * The RECEIVER cannot cover the requested side right now (missing copies, or
 * copies engaged in their own outgoing offers). The offer stays PENDING: the
 * situation may resolve itself (cancel an outgoing offer, obtain the card),
 * and the receiver can always refuse instead.
 */
final class TradeOfferUnacceptableException extends TradeException
{
}
