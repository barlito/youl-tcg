<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\BoosterPurchase;
use App\Repository\BoosterRepository;
use App\Repository\DiscordUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminBoosterPurchaseTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string CRUD_URL = '/admin/booster-purchase';

    public function testTheListShowsPurchasesAndTheStatusFilterNarrowsThem(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $this->purchase('juju-tx-1');
        $failed = $this->purchase(null);
        $failed->fail('Coin refused', new \DateTimeImmutable());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', self::CRUD_URL);

        self::assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('table tbody tr'));
        $this->assertStringContainsString('juju-tx-1', $crawler->filter('table')->text());

        $crawler = $client->request('GET', self::CRUD_URL, ['filters' => ['status' => ['comparison' => '=', 'value' => 'failed']]]);

        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertStringNotContainsString('juju-tx-1', $crawler->filter('table')->text());
    }

    public function testTheDetailPageShowsTheFailureReason(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $purchase = $this->purchase(null);
        $purchase->fail('Coin refused the payment (HTTP 422).', new \DateTimeImmutable());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $purchase->getId());

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Coin refused the payment (HTTP 422).', $crawler->filter('body')->text());
    }

    private function purchase(?string $transactionId): BoosterPurchase
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = self::getContainer()->get(DiscordUserRepository::class)->find('195659530363731968');
        $booster = self::getContainer()->get(BoosterRepository::class)->findPublished()[0];

        $purchase = new BoosterPurchase($user, $booster, 10, new \DateTimeImmutable());
        if (null !== $transactionId) {
            $purchase->complete($transactionId, new \DateTimeImmutable());
        }
        $entityManager->persist($purchase);
        $entityManager->flush();

        return $purchase;
    }
}
