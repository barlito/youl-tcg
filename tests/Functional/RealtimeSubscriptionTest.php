<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class RealtimeSubscriptionTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string COOKIE_NAME = '__Secure-mercure_access_token';

    private const string DETAIL_TYPE = 'https://mercure.rocks/authorization-detail';

    public function testTheLayoutSubscribesThePlayerToTheirOwnTopicOnly(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, '195659530363731968');

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        // Mercure 1.0 exact matcher: `match=`, never the 0.x `topic=`
        $url = (string) $crawler->filter('[data-testid="live-updates"]')->attr('data-live-updates-url-value');
        $this->assertSame(
            'https://localhost/.well-known/mercure?match=' . rawurlencode('https://localhost/users/195659530363731968') . '&match=' . rawurlencode('https://localhost/broadcast'),
            $url,
        );

        [, $claims] = $this->decode($this->authorizationCookie($client->getResponse()->headers->getCookies()));

        // the JWT only unlocks this player's private topic (the broadcast is public), and never publishing
        $this->assertSame([[
            'type' => self::DETAIL_TYPE,
            'actions' => ['subscribe'],
            'topics' => [['match' => 'https://localhost/users/195659530363731968']],
        ]], $claims['authorization_details']);
        $this->assertArrayNotHasKey('mercure', $claims);
    }

    public function testTheCookieIsAnRfc9068AccessTokenWhoseSubjectIsThePlayer(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, '195659530363731968');

        $client->request('GET', '/');

        [$header, $claims] = $this->decode($this->authorizationCookie($client->getResponse()->headers->getCookies()));

        $this->assertSame('at+jwt', $header['typ']);
        $this->assertSame('HS256', $header['alg']);
        // must equal the hub's `issuer` block and `resource_identifier` (Caddyfile)
        $this->assertSame('https://localhost', $claims['iss']);
        $this->assertSame('https://localhost/.well-known/mercure', $claims['aud']);
        $this->assertSame('https://localhost/users/195659530363731968', $claims['sub']);
        $this->assertSame('youl-tcg', $claims['client_id']);
        $this->assertIsString($claims['jti']);
        $this->assertIsNumeric($claims['iat']);
        $this->assertIsNumeric($claims['exp']);
        // default_cookie_lifetime: 12h
        $this->assertEqualsWithDelta(time() + 43200, (int) $claims['exp'], 60);
    }

    public function testAnotherPlayerGetsAnotherTopic(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, '188967649332428800');

        $client->request('GET', '/');

        [, $claims] = $this->decode($this->authorizationCookie($client->getResponse()->headers->getCookies()));
        $this->assertSame('https://localhost/users/188967649332428800', $claims['sub']);
        $this->assertSame([['match' => 'https://localhost/users/188967649332428800']], $claims['authorization_details'][0]['topics']);
    }

    public function testAnAnonymousVisitorGetsNoSubscription(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $mercureCookies = array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn (Cookie $cookie): bool => str_contains($cookie->getName(), 'mercure'),
        );
        $this->assertSame([], $mercureCookies);
        $this->assertStringNotContainsString('live-updates', (string) $client->getResponse()->getContent());
    }

    /**
     * @param list<Cookie> $cookies
     */
    private function authorizationCookie(array $cookies): Cookie
    {
        foreach ($cookies as $cookie) {
            $this->assertNotSame('mercureAuthorization', $cookie->getName());

            if (self::COOKIE_NAME === $cookie->getName()) {
                $this->assertSame('/.well-known/mercure', $cookie->getPath());
                $this->assertTrue($cookie->isHttpOnly());
                $this->assertTrue($cookie->isSecure());

                return $cookie;
            }
        }

        $this->fail('No ' . self::COOKIE_NAME . ' cookie set.');
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function decode(Cookie $cookie): array
    {
        $parts = explode('.', (string) $cookie->getValue());
        $this->assertCount(3, $parts);

        $decode = static fn (string $part): mixed => json_decode(base64_decode(strtr($part, '-_', '+/'), true) ?: '', true, flags: \JSON_THROW_ON_ERROR);
        $header = $decode($parts[0]);
        $payload = $decode($parts[1]);
        $this->assertIsArray($header);
        $this->assertIsArray($payload);

        return [$header, $payload];
    }
}
