<?php

declare(strict_types=1);

namespace App\Attribute;

use App\Enum\FeatureEnum;

/**
 * The controller (or Live Component) answers 404 while the feature is OFF.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class RequiresFeature
{
    public function __construct(
        public FeatureEnum $feature,
    ) {
    }
}
