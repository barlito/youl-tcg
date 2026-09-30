<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UniverseCompletionReward;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testAdminEntryPointIsTheEconomyDashboard(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="kpi-players"]'));
    }

    public function testDashboardRendersTheCoinBlock(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="coin"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="kpi-coin-bank-net"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="coin-market-prices"]'));
    }

    public function testDashboardAlertsOnAPendingRewardWithItsReconcileCommand(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(DiscordUser::class)->findOneBy([]) ?? throw new \LogicException('No fixture player.');
        $extension = $entityManager->getRepository(Extension::class)->findOneBy([]) ?? throw new \LogicException('No fixture extension.');
        $entityManager->getConnection()->executeStatement('DELETE FROM universe_completion_reward');
        $entityManager->persist(new UniverseCompletionReward($user, $extension, 100, new \DateTimeImmutable()));
        $entityManager->flush();

        self::getContainer()->get('cache.app')->delete('admin_economy_dashboard_30');
        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $alert = $crawler->filter('[data-testid="alert-reward-pending"]');
        $this->assertCount(1, $alert);
        $this->assertStringContainsString('app:coin:pay-pending-rewards', $alert->text());
        $this->assertStringContainsString('universe-completion-reward', (string) $alert->filter('a')->attr('href'));
    }
}
