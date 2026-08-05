<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Security\RefreshTokenRedirector;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: Events::JWT_NOT_FOUND, method: 'onJwtNotFound')]
readonly class JwtNotFound
{
    public function __construct(
        private RefreshTokenRedirector $refreshTokenRedirector,
    ) {
    }

    public function onJwtNotFound(JWTNotFoundEvent $event): void
    {
        $event->setResponse($this->refreshTokenRedirector->createRedirect($event->getRequest()));
    }
}
