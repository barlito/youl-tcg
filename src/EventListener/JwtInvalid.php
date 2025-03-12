<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DiscordUser;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

#[AsEventListener(event: Events::JWT_INVALID, method: 'onJwtInvalid')]
readonly class JwtInvalid
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenExtractorInterface $tokenExtractor,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
    }

    /** Create the user blindfolded, we trust the token provider */
    public function onJwtInvalid(JWTInvalidEvent $event): void
    {
        $token = $this->tokenExtractor->extract($event->getRequest());
        if (false === $token) {
            return;
        }

        $payload = $this->jwtManager->parse($token);
        if ([] === $payload) {
            return;
        }

        if ($event->getException()->getPrevious() instanceof UserNotFoundException) {
            $user = (new DiscordUser())
                ->setDiscordId($payload['discordId'])
                ->setUsername($payload['username'])
                ->setRoles($payload['roles'])
            ;
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        $event->setResponse(new RedirectResponse($event->getRequest()->getUri()));
    }
}
