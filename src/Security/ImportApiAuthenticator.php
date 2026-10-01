<?php

declare(strict_types=1);

namespace App\Security;

use App\Dto\Admin\ImportTokenInfo;
use App\Service\Admin\ImportApiTokenManager;
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

final class ImportApiAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly ImportApiTokenManager $tokenManager)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $header = (string) $request->headers->get('Authorization');
        if (1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired token.');
        }

        $info = $this->tokenManager->validate($matches[1]);
        if (!$info instanceof ImportTokenInfo) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired token.');
        }

        return new SelfValidatingPassport(new UserBadge(
            'import-api:' . $info->generatedBy,
            static fn (): ImportApiUser => new ImportApiUser($info->generatedBy),
        ));
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
        return new JsonResponse(
            ['error' => 'Unauthorized', 'message' => 'Missing, invalid or expired token.'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
