<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class RealtimeSubscriptionTest extends WebTestCase
{
    use JwtAuthTrait;

    public function testTheLayoutSubscribesThePlayerToTheirOwnTopicOnly(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, '195659530363731968');

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $url = (string) $crawler->filter('[data-testid="live-updates"]')->attr('data-live-updates-url-value');
        $this->assertSame(
            'https://localhost/.well-known/mercure?topic=' . rawurlencode('https://localhost/users/195659530363731968'),
            $url,
        );

        $claims = $this->mercureClaims($this->authorizationCookie($client->getResponse()->headers->getCookies()));
        // the JWT only unlocks this player's private topic, and never publishing
        $this->assertSame(['https://localhost/users/195659530363731968'], $claims['subscribe']);
        $this->assertArrayNotHasKey('publish', array_filter($claims));
    }

    public function testAnotherPlayerGetsAnotherTopic(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, '188967649332428800');

        $client->request('GET', '/');

        $claims = $this->mercureClaims($this->authorizationCookie($client->getResponse()->headers->getCookies()));
        $this->assertSame(['https://localhost/users/188967649332428800'], $claims['subscribe']);
    }

    /**
     * @param list<Cookie> $cookies
     */
    private function authorizationCookie(array $cookies): Cookie
    {
        foreach ($cookies as $cookie) {
            if ('mercureAuthorization' === $cookie->getName()) {
                $this->assertSame('/.well-known/mercure', $cookie->getPath());
                $this->assertTrue($cookie->isHttpOnly());
                $this->assertTrue($cookie->isSecure());

                return $cookie;
            }
        }

        $this->fail('No mercureAuthorization cookie set.');
    }

    /**
     * @return array<string, mixed>
     */
    private function mercureClaims(Cookie $cookie): array
    {
        $parts = explode('.', (string) $cookie->getValue());
        $this->assertCount(3, $parts);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['mercure'] ?? null);

        return $payload['mercure'];
    }
}
