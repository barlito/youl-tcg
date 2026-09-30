<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\BoosterPurchase;
use App\Enum\Booster\BoosterPurchaseStatusEnum;
use App\Repository\BoosterRepository;
use App\Repository\DiscordUserRepository;
use App\Tests\Support\CoinMockResponses;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcilePurchasesCommandTest extends KernelTestCase
{
    public function testItResolvesThePendingPurchasesTheCoinKnows(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $purchase = new BoosterPurchase(
            self::getContainer()->get(DiscordUserRepository::class)->find('195659530363731968'),
            self::getContainer()->get(BoosterRepository::class)->findPublished()[0],
            10,
            new \DateTimeImmutable('-1 minute'),
        );
        $entityManager->persist($purchase);
        $entityManager->flush();
        self::getContainer()->get(CoinMockResponses::class)->override('GET', '/api/transactions', static fn () => CoinMockResponses::json(['hydra:member' => [['id' => 'tx-1']]]));

        $tester = new CommandTester(new Application(self::$kernel)->find('app:coin:reconcile-purchases'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('1 pending purchase(s), 1 resolved.', $tester->getDisplay());
        $this->assertSame(BoosterPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
    }
}
