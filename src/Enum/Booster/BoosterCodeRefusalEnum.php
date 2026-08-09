<?php

declare(strict_types=1);

namespace App\Enum\Booster;

/**
 * Why a redemption was refused. Carried as the violation code by the
 * RedeemableBoosterCode constraint, then re-exposed by
 * BoosterCodeRefusedException so callers can branch on the reason instead of
 * matching on a message.
 */
enum BoosterCodeRefusalEnum: string
{
    /** Blank or malformed input — never reached the lookup. */
    case INVALID_INPUT = 'invalid_input';

    /** Unknown or revoked: one reason on purpose, see the constraint messages. */
    case UNKNOWN = 'unknown';

    case EXPIRED = 'expired';

    case ALREADY_REDEEMED = 'already_redeemed';

    case EXHAUSTED = 'exhausted';

    /** Valid code, but its booster cannot be granted yet: no use is consumed. */
    case NOT_AVAILABLE_YET = 'not_available_yet';
}
