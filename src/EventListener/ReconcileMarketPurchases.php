<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DiscordUser;
use App\Service\Market\MarketPurchaseService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

// After the response is sent: resuming an unconfirmed market step never delays a page
#[AsEventListener(event: TerminateEvent::class)]
readonly class ReconcileMarketPurchases
{
    public function __construct(
        private Security $security,
        private MarketPurchaseService $purchaseService,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $user = $this->security->getUser();

        if ($event->isMainRequest() && $event->getRequest()->isMethod('GET') && $user instanceof DiscordUser) {
            $this->purchaseService->reconcile($user);
        }
    }
}
