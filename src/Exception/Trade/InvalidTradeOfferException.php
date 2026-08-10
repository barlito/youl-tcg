<?php

declare(strict_types=1);

namespace App\Exception\Trade;

/**
 * The request itself is refused: malformed offer (empty side, self-trade,
 * bad quantities), insufficient or already-engaged copies at creation, or an
 * action performed by the wrong player / on a non-pending offer.
 */
final class InvalidTradeOfferException extends TradeException
{
}
