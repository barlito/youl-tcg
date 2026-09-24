<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\RequiresFeature;
use App\Service\Feature\FeatureFlags;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces #[RequiresFeature] on every request, sub-requests included: Live
 * Component actions and re-renders (/_components/..., batch sub-requests) run
 * the component as controller, so its class attribute guards them too.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: 64)]
final readonly class RequiresFeatureListener
{
    public function __construct(
        private FeatureFlags $featureFlags,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        foreach ($event->getAttributes(RequiresFeature::class) as $attribute) {
            $this->featureFlags->assertEnabled($attribute->feature);
        }
    }
}
