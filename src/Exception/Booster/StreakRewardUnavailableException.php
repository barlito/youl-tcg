<?php

declare(strict_types=1);

namespace App\Exception\Booster;

/**
 * The streak reward cannot be spent: unknown id, someone else's reward, or
 * its bonus booster was already picked (possibly by a concurrent request).
 */
final class StreakRewardUnavailableException extends BoosterException
{
}
