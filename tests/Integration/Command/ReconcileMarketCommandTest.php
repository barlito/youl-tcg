<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Card;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Tests\Support\CoinMockResponses;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileMarketCommandTest extends KernelTestCase
{
    public function testItResumesTheUnsettledPurchasesTheCoinKnows(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $users = self::getContainer()->get(DiscordUserRepository::class);
        $seller = $users->find('188967649332428800');
        $buyer = $users->find('195659530363731968');
        $card = $entityManager->getRepository(Card::class)->findOneBy(['uniqueFlag' => false]);
        $entityManager->getConnection()->executeStatement(
            'INSERT INTO user_card (discord_user_id, card_id, quantity, holo_quantity, created_at, updated_at) VALUES (?, ?, 1, 0, NOW(), NOW()) ON CONFLICT (discord_user_id, card_id) DO UPDATE SET quantity = 1, holo_quantity = 0',
            [$seller->getDiscordId(), (string) $card->getId()],
        );
        $listing = new MarketListing($seller, $card, false, 10);
        $listing->reserveForPurchase();
        $purchase = new MarketPurchase($listing, $buyer, $seller, 10, '50000000', new \DateTimeImmutable('-1 minute'));
        $entityManager->persist($listing);
        $entityManager->persist($purchase);
        $entityManager->flush();
        self::getContainer()->get(CoinMockResponses::class)->override('GET', '/api/transactions', static fn () => CoinMockResponses::json(['hydra:member' => [['id' => 'tx-1']]]));

        $tester = new CommandTester(new Application(self::$kernel)->find('app:coin:reconcile-market'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('1 unsettled market purchase(s), 1 resolved.', $tester->getDisplay());
        $this->assertSame(MarketPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertSame(MarketListingStatusEnum::SOLD, $listing->getStatus());
    }
}
