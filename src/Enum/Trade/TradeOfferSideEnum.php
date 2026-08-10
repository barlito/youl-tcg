<?php

declare(strict_types=1);

namespace App\Enum\Trade;

/**
 * Which inventory a trade line moves from: OFFERED lines leave the proposer's
 * inventory, REQUESTED lines leave the receiver's.
 */
enum TradeOfferSideEnum: string
{
    case OFFERED = 'offered';

    case REQUESTED = 'requested';
}
