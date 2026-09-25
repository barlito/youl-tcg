<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Enum\Trade\TradeOfferStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

final class AdminDashboardTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string DISCORD_ID_JUJU = '195659530363731968';

    public function testAdminSeesTilesChartsAndTables(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');

        foreach (['players', 'active', 'openings', 'cards', 'uniques', 'unopened'] as $kpi) {
            $this->assertCount(1, $crawler->filter(\sprintf('[data-testid="kpi-%s"] .eco-tile__value', $kpi)), $kpi);
        }

        $this->assertCount(6, $crawler->filter('canvas[data-controller="symfony--ux-chartjs--chart"]'));
        foreach (['created', 'accepted', 'refused', 'rate'] as $tile) {
            $this->assertCount(1, $crawler->filter(\sprintf('[data-testid="trades"] [data-testid="kpi-trades-%s"] .eco-tile__value', $tile)), $tile);
        }
        $this->assertStringContainsString('ouverture, claim ou échange', $crawler->filter('[data-testid="kpi-active"]')->text());
        $this->assertCount(4, $crawler->filter('[data-testid="channels"] tbody tr'));
        $this->assertSame(1, $crawler->filter('[data-testid="rarity-holo"], [data-testid="rarities-empty"]')->count(), 'comparison table or empty state');
        $this->assertCount(1, $crawler->filter('script[type="importmap"]'));
        $this->assertCount(1, $crawler->filter('link[href*="/styles/admin/dashboard"]'));
        $this->assertSame('true', $crawler->filter('[data-testid="period-30"]')->attr('aria-current'));
    }

    public function testRarityComparisonRendersOnceSomethingWasOpened(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $booster = $entityManager->getRepository(Booster::class)->findOneBy([]);
        $card = $entityManager->getRepository(Card::class)->findOneBy(['uniqueFlag' => false]);
        $this->assertNotNull($booster);
        $this->assertNotNull($card);

        $opening = new BoosterOpening($user, $booster, 1, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $opening->addBoosterOpeningCard(new BoosterOpeningCard($opening, $card, 1, 1));
        $entityManager->persist($opening);
        $entityManager->flush();
        self::getContainer()->get(CacheInterface::class)->delete('admin_economy_dashboard_7');

        $crawler = $client->request('GET', '/admin?period=7');

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="rarities-empty"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="gap-holo"]'));
        $this->assertCount(1, $crawler->filter(\sprintf('[data-testid="rarity-%s"]', $card->getRarity()->value)));
    }

    public function testTradesBlockRendersCountsAndAcceptanceRate(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $receiver = $entityManager->find(DiscordUser::class, self::DISCORD_ID_JUJU);
        $this->assertNotNull($receiver);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ([TradeOfferStatusEnum::ACCEPTED, TradeOfferStatusEnum::ACCEPTED, TradeOfferStatusEnum::ACCEPTED, TradeOfferStatusEnum::REFUSED] as $status) {
            $entityManager->persist(new TradeOffer()->setProposer($user)->setReceiver($receiver)->resolve($status, $now));
        }

        $entityManager->flush();
        self::getContainer()->get(CacheInterface::class)->delete('admin_economy_dashboard_7');

        $crawler = $client->request('GET', '/admin?period=7');

        self::assertResponseIsSuccessful();
        $this->assertSame('4', $crawler->filter('[data-testid="kpi-trades-created"] .eco-tile__value')->text());
        $this->assertSame('3', $crawler->filter('[data-testid="kpi-trades-accepted"] .eco-tile__value')->text());
        $this->assertSame('1', $crawler->filter('[data-testid="kpi-trades-refused"] .eco-tile__value')->text());
        $this->assertSame('75,0 %', $crawler->filter('[data-testid="kpi-trades-rate"] .eco-tile__value')->text());
    }

    public function testPeriodIsSelectableAndUnknownValuesFallBackToThirtyDays(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/admin?period=7');
        self::assertResponseIsSuccessful();
        $this->assertSame('true', $crawler->filter('[data-testid="period-7"]')->attr('aria-current'));

        $crawler = $client->request('GET', '/admin?period=365');
        self::assertResponseIsSuccessful();
        $this->assertSame('true', $crawler->filter('[data-testid="period-30"]')->attr('aria-current'));
    }

    public function testMenuLinksToTheDashboard(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/admin/card');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Tableau de bord', $crawler->filter('#main-menu')->text());
    }

    public function testNonAdminIsForbidden(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $this->authenticateClient($client, self::DISCORD_ID_JUJU);

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }
}
