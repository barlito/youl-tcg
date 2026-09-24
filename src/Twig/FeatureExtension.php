<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\FeatureEnum;
use App\Service\Feature\FeatureFlags;
use Twig\Attribute\AsTwigFunction;

final readonly class FeatureExtension
{
    public function __construct(
        private FeatureFlags $featureFlags,
    ) {
    }

    /**
     * Strict on purpose: a misspelled name throws instead of hiding a link forever.
     */
    #[AsTwigFunction(name: 'feature_enabled')]
    public function featureEnabled(string $name): bool
    {
        return $this->featureFlags->isEnabled(FeatureEnum::from($name));
    }
}
