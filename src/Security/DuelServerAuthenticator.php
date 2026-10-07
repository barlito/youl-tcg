<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Bearer DUEL_SERVER_TOKEN shared with the game server; an empty secret refuses everything.
 */
final class DuelServerAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        #[Autowire(env: 'DUEL_SERVER_TOKEN')]
        private readonly string $serverToken,
    ) {
    }

    public function supports(Request $request): bool
    {
        return true;
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $header = (string) $request->headers->get('Authorization');
        $valid = '' !== $this->serverToken
            && 1 === preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)
            && hash_equals($this->serverToken, $matches[1]);
        if (!$valid) {
            throw new CustomUserMessageAuthenticationException('Invalid duel server token.');
        }

        return new SelfValidatingPassport(new UserBadge('duel-server', static fn (): DuelServerUser => new DuelServerUser()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        return $this->unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): JsonResponse
    {
        return $this->unauthorized();
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Jeton serveur manquant ou invalide.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
