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

        // Compare canonical sets: getRoles() always appends ROLE_USER, so a
        // raw comparison against a token without it (or ordered differently)
        // mismatches forever — flushing an UPDATE on every single request.
        if ($this->normalize($tokenRoles) !== $this->normalize($user->getRoles())) {
            $user->setRoles($tokenRoles);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function normalize(array $roles): array
    {
        $roles[] = 'ROLE_USER';
        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }
}
