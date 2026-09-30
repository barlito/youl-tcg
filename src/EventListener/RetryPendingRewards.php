<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DiscordUser;
use App\Service\Coin\UniverseRewardService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

// After the response is sent: retrying a reward whose payment was uncertain never delays a page
#[AsEventListener(event: TerminateEvent::class)]
readonly class RetryPendingRewards
{
    public function __construct(
        private Security $security,
        private UniverseRewardService $rewardService,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $user = $this->security->getUser();

        if ($event->isMainRequest() && $event->getRequest()->isMethod('GET') && $user instanceof DiscordUser) {
            $this->rewardService->payPending($user);
        }
    }
}
