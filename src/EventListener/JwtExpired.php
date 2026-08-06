<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Security\RefreshTokenRedirector;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: Events::JWT_EXPIRED, method: 'onJwtExpired')]
readonly class JwtExpired
{
    public function __construct(
        private RefreshTokenRedirector $refreshTokenRedirector,
    ) {
    }

    public function onJwtExpired(JWTExpiredEvent $event): void
    {
        $event->setResponse($this->refreshTokenRedirector->createRedirect($event->getRequest()));
    }
}
