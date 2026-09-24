<?php

declare(strict_types=1);

namespace App\Service\Feature;

use App\Enum\FeatureEnum;
use App\Exception\Feature\FeatureDisabledException;
use App\Repository\FeatureFlagRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the FeatureFlag table once per request (memoized, reset between
 * requests). A feature without a row is OFF.
 */
final class FeatureFlags implements ResetInterface
{
    /**
     * @var array<string, bool>|null
     */
    private ?array $states = null;

    public function __construct(
        private readonly FeatureFlagRepository $featureFlagRepository,
    ) {
    }

    public function isEnabled(FeatureEnum $feature): bool
    {
        $this->states ??= $this->featureFlagRepository->findStates();

        return $this->states[$feature->value] ?? false;
    }

    /**
     * @throws FeatureDisabledException
     */
    public function assertEnabled(FeatureEnum $feature): void
    {
        if (!$this->isEnabled($feature)) {
            throw new FeatureDisabledException($feature);
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->states = null;
    }
}
