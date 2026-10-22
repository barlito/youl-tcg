<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\FeatureEnum;
use App\Tests\FeatureFlagTrait;
use App\Twig\Components\BoostersNavLink;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class LayoutTest extends WebTestCase
{
    use FeatureFlagTrait;
    use InteractsWithLiveComponents;
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
        $this->assertCount(1, $crawler->filter('[data-testid="mobile-tabbar"]'));
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
        $this->assertStringContainsString('bientôt', $header);
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

        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('[data-testid="app-version"]')->count());
        $this->assertSame('dev', $crawler->filter('[data-testid="app-version"]')->first()->text());
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

    public function testAccountMenuLinksToMySpaceAndLogout(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client);

        $crawler = $client->request('GET', '/');

        $menu = $crawler->filter('header [data-testid="account-menu"]');
        $this->assertCount(1, $menu);
        $this->assertNotNull($menu->attr('hidden'), 'Closed until the account button opens it.');
        $this->assertSame('account-menu', $crawler->filter('[data-testid="account-menu-toggle"]')->attr('aria-controls'));
        $this->assertStringContainsString($user->getUsername(), $menu->text());
        $this->assertCount(1, $menu->filter('a[href="/collection"]'));
        $this->assertCount(1, $menu->filter('a[href="/logout"]'));
    }

    public function testBoostersEntriesShowTheFreeClaimsLeft(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::DISCORD_ID_JUJU);

        $crawler = $client->request('GET', '/');

        // test env: real daily quota, Juju has claimed nothing today
        $this->assertSame('2', trim($crawler->filter('header [data-testid="claims-badge"]')->text()));
        $this->assertSame('2', trim($crawler->filter('[data-testid="mobile-tabbar"] [data-testid="claims-badge-tab"]')->text()));
        $this->assertStringContainsString('live-updates:inventory-changed@window->live#$render', (string) $crawler->filter('[data-testid="nav-boosters"]')->attr('data-action'));
    }

    public function testTheBoostersBadgeIsALiveComponent(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::DISCORD_ID_JUJU);

        $html = (string) $this->createLiveComponent(BoostersNavLink::class, ['variant' => 'tab'], $client)->render();

        $this->assertStringContainsString('data-testid="tab-boosters"', $html);
        $this->assertStringContainsString('data-testid="claims-badge-tab"', $html);
    }

    public function testMobileTabBarAndMoreSheetFollowTheFeatureFlags(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/recyclage');
        $tabbar = $crawler->filter('[data-testid="mobile-tabbar"]');
        $sheet = $crawler->filter('[data-testid="more-sheet"]');

        $this->assertCount(1, $tabbar->filter('[data-testid="tab-trades"]'));
        $this->assertSame('page', $tabbar->filter('a[href="/collection"]')->attr('aria-current'));
        $this->assertSame('more-sheet', $tabbar->filter('[data-testid="tab-more"]')->attr('aria-controls'));
        $this->assertNotNull($sheet->attr('hidden'));
        foreach (['/univers', '/marche', '/classement', '/wishlist', '/mes-ouvertures', '/recyclage', '/fusion', '/admin', '/logout'] as $href) {
            $this->assertCount(1, $sheet->filter(\sprintf('a[href="%s"]', $href)), $href);
        }

        $this->setFeature(FeatureEnum::TRADES, false);
        $this->setFeature(FeatureEnum::FUSION, false);
        $this->setFeature(FeatureEnum::WISHLIST, false);
        $crawler = $client->request('GET', '/');
        $tabbar = $crawler->filter('[data-testid="mobile-tabbar"]');
        $sheet = $crawler->filter('[data-testid="more-sheet"]');

        $this->assertCount(0, $tabbar->filter('a[href="/echanges"]'));
        $this->assertCount(1, $tabbar->filter('a[href="/univers"]'), 'Univers takes the trades slot.');
        $this->assertCount(0, $sheet->filter('a[href="/univers"], a[href="/fusion"], a[href="/wishlist"]'));
    }

    public function testMySpaceSubNavMarksTheCurrentTabAndFollowsTheFlags(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/mes-ouvertures');
        $nav = $crawler->filter('main [data-testid="my-space-nav"]');

        $this->assertSame('page', $nav->filter('a[href="/mes-ouvertures"]')->attr('aria-current'));
        $this->assertCount(5, $nav->filter('a.tab'));

        $this->setFeature(FeatureEnum::RECYCLING, false);
        $crawler = $client->request('GET', '/mes-ouvertures');

        $this->assertCount(0, $crawler->filter('main [data-testid="my-space-nav"] a[href="/recyclage"]'));
    }
}
