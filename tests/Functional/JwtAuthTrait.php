<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\DiscordUser;
use App\Repository\DiscordUserRepository;
use App\Service\Security\TokenRoleMapper;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Authenticates the test client the same way production works: a signed JWT
 * in the "jwt" cookie (stateless firewall, cookie extractor).
 */
trait JwtAuthTrait
{
    /**
     * @param list<string>|null $tokenRoles roles carried by the token, as youl-coin emits them (default: the user's own, mapped)
     */
    private function authenticateClient(KernelBrowser $client, string $discordId = '188967649332428800', ?array $tokenRoles = null): DiscordUser
    {
        $user = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);

        if (!$user instanceof DiscordUser) {
            throw new \LogicException(\sprintf('Fixture user "%s" not found, load the alice fixtures first.', $discordId));
        }

        $tokenRoles ??= static::getContainer()->get(TokenRoleMapper::class)->toToken($user->getRoles());
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($user, ['roles' => $tokenRoles]);
        $client->getCookieJar()->set(new Cookie('jwt', $token));

        return $user;
    }
}
