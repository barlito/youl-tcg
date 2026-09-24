<?php

declare(strict_types=1);

namespace App\Exception\Feature;

use App\Enum\FeatureEnum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A switched-off feature does not exist for players: a plain 404.
 */
final class FeatureDisabledException extends NotFoundHttpException
{
    public function __construct(public readonly FeatureEnum $feature)
    {
        parent::__construct(\sprintf('Feature "%s" is disabled.', $feature->value));
    }
}
