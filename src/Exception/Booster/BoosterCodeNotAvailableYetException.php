<?php

declare(strict_types=1);

namespace App\Exception\Booster;

/**
 * The code is valid but its booster cannot be granted yet (unpublished
 * extension, or no published card to draw). Thrown before the use is consumed:
 * a code handed out ahead of a release must still work on release day.
 */
final class BoosterCodeNotAvailableYetException extends BoosterException
{
}
