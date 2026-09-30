<?php

declare(strict_types=1);

namespace App\Enum\Coin;

enum CoinTransactionTypeEnum: string
{
    case PURCHASE = 'purchase';
    case REWARD = 'reward';
    case MARKET_PAYMENT = 'market_payment';
    case MARKET_PAYOUT = 'market_payout';
    case MARKET_REFUND = 'market_refund';
}
