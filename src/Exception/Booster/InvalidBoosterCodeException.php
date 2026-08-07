<?php

declare(strict_types=1);

namespace App\Exception\Booster;

/**
 * Unknown or revoked code. Both cases share one player-facing message: telling
 * a revoked code apart from a made-up one only helps someone probing codes.
 */
final class InvalidBoosterCodeException extends BoosterException
{
}
