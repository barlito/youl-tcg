<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\CoinSettings;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Tests\Support\CoinMockResponses;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminMarketTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string ADMIN = '188967649332428800';
    private const string JUJU = '195659530363731968';

    public function testTheListingsAreListedAndFilterableByStatus(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $this->listing(MarketListingStatusEnum::ACTIVE);
        $this->listing(MarketListingStatusEnum::WITHDRAWN);

        $crawler = $client->request('GET', '/admin/market-listing');
        self::assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('table tbody tr'));

        $crawler = $client->request('GET', '/admin/market-listing', ['filters' => ['status' => ['comparison' => '=', 'value' => 'withdrawn']]]);
        $this->assertCount(1, $crawler->filter('table tbody tr'));

        $client->request('GET', '/admin/market-listing/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePurchaseDetailShowsTheCommissionAndTheTransactions(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $purchase = $this->purchase();
        $purchase->markCardTransferred('tx-payment-abc');
        $purchase->complete('tx-payout-def', new \DateTimeImmutable());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/admin/market-purchase');
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Terminée', $crawler->filter('table')->text());

        $crawler = $client->request('GET', '/admin/market-purchase/' . $purchase->getId());
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        $this->assertStringContainsString('tx-payment-abc', $text);
        $this->assertStringContainsString('tx-payout-def', $text);
        $this->assertStringContainsString('0,5', $text);

        $client->request('POST', '/admin/market-purchase/' . $purchase->getId() . '/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheMarketFeeIsEditableAndBounded(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $form = $client->request('GET', '/admin/coin-settings/1/edit')->filter('form[name="CoinSettings"]')->form();
        $this->assertSame('5', $form['CoinSettings[marketFeePercent]']->getValue());

        $form['CoinSettings[marketFeePercent]'] = '101';
        $crawler = $client->submit($form);
        $this->assertStringContainsString('100', $crawler->filter('body')->text());

        $form = $client->request('GET', '/admin/coin-settings/1/edit')->filter('form[name="CoinSettings"]')->form();
        $form['CoinSettings[marketFeePercent]'] = '8';
        $client->submit($form);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $this->assertSame(8, $entityManager->find(CoinSettings::class, 1)?->getMarketFeePercent());
    }

    public function testAPageViewResumesThePlayersUnsettledPurchase(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->authenticateClient($client, self::JUJU);
        $purchase = $this->purchase(new \DateTimeImmutable('-11 minutes'));
        self::getContainer()->get(CoinMockResponses::class)->override('GET', '/api/transactions', static fn () => CoinMockResponses::json(['hydra:member' => []]));

        $client->request('GET', '/');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->refresh($purchase);
        $this->assertSame(MarketPurchaseStatusEnum::FAILED, $purchase->getStatus());
        $this->assertSame(MarketListingStatusEnum::ACTIVE, $purchase->getListing()->getStatus());
    }

    private function listing(MarketListingStatusEnum $status): MarketListing
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $listing = new MarketListing(
            self::getContainer()->get(DiscordUserRepository::class)->find(self::ADMIN),
            $entityManager->getRepository(Card::class)->findOneBy([]),
            false,
            10,
        );
        $listing->close($status, new \DateTimeImmutable());
        $entityManager->persist($listing);
        $entityManager->flush();

        return $listing;
    }

    private function purchase(?\DateTimeImmutable $requestedAt = null): MarketPurchase
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $users = self::getContainer()->get(DiscordUserRepository::class);
        $listing = new MarketListing($users->find(self::ADMIN), $entityManager->getRepository(Card::class)->findOneBy([]), false, 10);
        $listing->reserveForPurchase();
        $purchase = new MarketPurchase($listing, $users->find(self::JUJU), $users->find(self::ADMIN), 10, '50000000', $requestedAt ?? new \DateTimeImmutable());
        $entityManager->persist($listing);
        $entityManager->persist($purchase);
        $entityManager->flush();

        return $purchase;
    }
}
