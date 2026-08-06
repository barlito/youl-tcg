<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LayoutTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string DISCORD_ID_JUJU = '195659530363731968';

    /**
     * @return iterable<string, array{string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'homepage' => ['/'];
        yield 'universes' => ['/univers'];
        yield 'boosters' => ['/boosters'];
    }

    #[DataProvider('pageProvider')]
    public function testPageRespondsWithSharedLayout(string $uri): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', $uri);

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('YOUL', $crawler->filter('header')->text());
        $this->assertStringContainsString('TRADING CARD GAME', $crawler->filter('header')->text());
        $this->assertStringContainsString('youl-made · non-officiel', $crawler->filter('footer')->text());
    }

    #[DataProvider('pageProvider')]
    public function testAnonymousIsRedirected(string $uri): void
    {
        $client = self::createClient();

        $client->request('GET', $uri);

        self::assertResponseRedirects();
    }

    public function testHeaderShowsUserChipAndSoonEntries(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $header = $crawler->filter('header')->text();
        $this->assertStringContainsString($user->getUsername(), $header);
        $this->assertStringContainsString('SOON', $header);
        $this->assertStringContainsString('Échanges', $header);
        $this->assertStringContainsString('Duels', $header);
        $this->assertCount(1, $crawler->filter('header a[href="/logout"]'));
    }

    public function testFooterShowsDeployedVersion(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // APP_VERSION defaults to 'dev' outside a release image (see .env)
        $this->assertSame('dev', $crawler->filter('footer [data-testid="app-version"]')->text());
    }

    public function testAdminTitleShowsDeployedVersion(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $client->request('GET', '/admin');
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('[data-testid="app-version"]')->count());
        $this->assertSame('dev', $crawler->filter('[data-testid="app-version"]')->first()->text());
    }

    public function testUmamiSnippetIsAbsentWhenUnconfigured(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // UMAMI_URL / UMAMI_WEBSITE_ID default to '' (see .env): no tracking
        $this->assertCount(0, $crawler->filter('script[data-website-id]'));
    }

    public function testAdminLinkOnlyVisibleForAdmin(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/');
        $this->assertCount(1, $crawler->filter('header a[href="/admin"]'));

        $this->authenticateClient($client, self::DISCORD_ID_JUJU);

        $crawler = $client->request('GET', '/');
        $this->assertCount(0, $crawler->filter('header a[href="/admin"]'));
    }
}
