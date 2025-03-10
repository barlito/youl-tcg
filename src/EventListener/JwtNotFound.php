<?php

declare(strict_types=1);

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: Events::JWT_NOT_FOUND, method: 'onJwtNotFound')]
readonly class JwtNotFound
{
    public function __construct(
        #[Autowire(env: 'REFRESH_TOKEN_URL')]
        private readonly string $refreshTokenUrl,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onJwtNotFound(JWTNotFoundEvent $event): void
    {
        $request = $event->getRequest();

        $targetPath = $this->urlGenerator->generate('homepage');
        if ($request instanceof Request) {
            $targetPath = urlencode($request->getUri());
        }

        $event->setResponse(new RedirectResponse($this->refreshTokenUrl . '?_target_path=' . $targetPath));
    }
}
