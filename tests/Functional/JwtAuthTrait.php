<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\DiscordUser;
use App\Repository\DiscordUserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Authenticates the test client the same way production works: a signed JWT
 * in the "jwt" cookie (stateless firewall, cookie extractor).
 */
trait JwtAuthTrait
{
    private function authenticateClient(KernelBrowser $client, string $discordId = '188967649332428800'): DiscordUser
    {
        $user = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);

        if (!$user instanceof DiscordUser) {
            throw new \LogicException(\sprintf('Fixture user "%s" not found, load the alice fixtures first.', $discordId));
        }

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client->getCookieJar()->set(new Cookie('jwt', $token));

        return $user;
    }
}
