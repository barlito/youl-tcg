<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DiscordUser;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: Events::JWT_AUTHENTICATED, method: 'onJwtAuthenticated')]
readonly class JwtAuthenticated
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $payload = $event->getPayload();
        $user = $event->getToken()->getUser();

        if (!$user instanceof DiscordUser) {
            return;
        }

        $tokenRoles = $payload['roles'] ?? [];
        $userRoles = $user->getRoles();

        if ($tokenRoles !== $userRoles) {
            $user->setRoles($tokenRoles);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }
    }
}
