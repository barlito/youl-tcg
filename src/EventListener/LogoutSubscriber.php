<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

readonly class LogoutSubscriber
{
    public function __construct(
        #[Autowire(env: 'LOGOUT_URL')]
        private string $logoutUrl,
    ) {
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse(new RedirectResponse($this->logoutUrl));
    }
}
