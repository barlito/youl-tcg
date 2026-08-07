<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminLayoutTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string CARDS_URL = '/admin/card';

    public function testAdminStylesheetIsLoadedOnEveryPage(): void
    {
        // it carries the live preview repositioning: registered on the dashboard
        // so that every CRUD page inherits it, not only the ones with an image field
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CARDS_URL);

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="/styles/admin/admin"]'));
    }

    public function testMenuHasNoDashboardDuplicateAndExposesTheEconomySection(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CARDS_URL);

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('#main-menu')->text();
        $this->assertStringNotContainsString('Dashboard', $menu);
        $this->assertStringContainsString('Économie', $menu);
        $this->assertStringContainsString('Joueurs', $menu);
    }

    public function testMenuLinksBackToTheSite(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CARDS_URL);

        self::assertResponseIsSuccessful();
        $back = $crawler->filter('#main-menu a:contains("Retour au site")');
        $this->assertCount(1, $back);
        // a route-based menu item would keep the admin context and never leave it
        $this->assertSame('/', $back->attr('href'));
    }

    public function testInterfaceIsInFrench(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CARDS_URL);

        self::assertResponseIsSuccessful();
        $this->assertSame('fr', $crawler->filter('html')->attr('lang'));
    }

    public function testCardCrudIsStillTheAdminEntryPoint(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $client->request('GET', '/admin');

        // EA5 pretty URLs: /admin lands on the cards listing
        self::assertResponseRedirects(self::CARDS_URL);
    }
}
