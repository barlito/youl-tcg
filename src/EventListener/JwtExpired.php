<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Security\RefreshTokenRedirector;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The bundle routes ExpiredTokenException to JWT_EXPIRED, not JWT_INVALID:
 * without this listener an expired token gets the raw 401 JSON response. In
 * practice the auth cookie usually dies together with the token (same 900s
 * lifetime), so the browser stops sending it and falls into the JwtNotFound
 * path — this listener covers the remaining cases (clock skew, cookie set
 * with a longer lifetime, replayed token).
 */
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
