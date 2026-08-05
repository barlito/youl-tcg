<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DiscordUser;
use App\Service\Security\RefreshTokenRedirector;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Auto-creates the DiscordUser the first time a valid token shows up for an
 * unknown user (we trust the external token provider), then replays the
 * original request. Every other invalid-token case redirects to the refresh
 * endpoint: the cookie lives on the whole parent domain, so a token signed by
 * another app must neither be parsed again (uncaught JWTDecodeFailureException,
 * 500) nor replayed on the same URI (infinite redirect loop).
 */
#[AsEventListener(event: Events::JWT_INVALID, method: 'onJwtInvalid')]
readonly class JwtInvalid
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenExtractorInterface $tokenExtractor,
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenRedirector $refreshTokenRedirector,
        private LoggerInterface $logger,
    ) {
    }

    public function onJwtInvalid(JWTInvalidEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request instanceof Request) {
            return;
        }

        if (!$event->getException()->getPrevious() instanceof UserNotFoundException) {
            // Malformed token, invalid signature, missing id claim... nothing
            // recoverable here: ask the auth app for a fresh token.
            $this->logger->warning('Invalid JWT token, redirecting to the refresh endpoint.', [
                'reason' => $event->getException()->getMessageKey(),
            ]);
            $event->setResponse($this->refreshTokenRedirector->createRedirect($request));

            return;
        }

        $userData = $this->extractValidatedPayload($request);
        if (null === $userData) {
            $event->setResponse($this->refreshTokenRedirector->createRedirect($request));

            return;
        }

        $user = new DiscordUser()
            ->setDiscordId($userData['discordId'])
            ->setUsername($userData['username'])
            ->setRoles($userData['roles'])
        ;
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $event->setResponse(new RedirectResponse($request->getUri()));
    }

    /**
     * @return array{discordId: non-empty-string, username: non-empty-string, roles: list<string>}|null
     */
    private function extractValidatedPayload(Request $request): ?array
    {
        $token = $this->tokenExtractor->extract($request);
        if (false === $token) {
            $this->logger->warning('JWT user auto-creation aborted: no token found in the request.');

            return null;
        }

        // On this path the token already decoded successfully during
        // authentication (only the user lookup failed), so parsing it again
        // cannot fail — the catch is defensive only.
        try {
            $payload = $this->jwtManager->parse($token);
        } catch (JWTDecodeFailureException $exception) {
            $this->logger->warning('JWT user auto-creation aborted: the token could not be parsed.', [
                'reason' => $exception->getReason(),
            ]);

            return null;
        }

        $discordId = $payload['discordId'] ?? null;
        $username = $payload['username'] ?? null;
        $roles = $payload['roles'] ?? [];

        if (
            !\is_string($discordId) || '' === $discordId
            || !\is_string($username) || '' === $username
            || !\is_array($roles)
        ) {
            $this->logger->warning('JWT payload rejected, user not created.', [
                'claims' => array_keys($payload),
            ]);

            return null;
        }

        $checkedRoles = [];
        foreach ($roles as $role) {
            if (!\is_string($role)) {
                $this->logger->warning('JWT payload rejected, the roles claim is not a list of strings.', [
                    'claims' => array_keys($payload),
                ]);

                return null;
            }
            $checkedRoles[] = $role;
        }

        return ['discordId' => $discordId, 'username' => $username, 'roles' => $checkedRoles];
    }
}
