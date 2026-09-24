<?php

declare(strict_types=1);

namespace App\Exception\Trade;

/**
 * A concurrent write beat us to it (unique-claim transfer lost, duplicate
 * inventory row insert…): the whole transaction was rolled back and nothing
 * moved. Retrying is safe.
 */
final class TradeConflictException extends TradeException
{
}
