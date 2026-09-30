<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Booster;
use App\Entity\BoosterPurchase;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Booster\BoosterPurchaseStatusEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Exception\Booster\BoosterPurchaseRefusedException;
use App\Service\Booster\BoosterPurchaseService;
use App\Service\Time\ParisDay;
use App\Tests\Support\CoinMockResponses;
use App\Tests\Support\SpyHub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BoosterPurchaseServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private BoosterPurchaseService $service;

    private CoinMockResponses $coin;

    private DiscordUser $user;

    private Booster $booster;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('cache.app')->clear();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(BoosterPurchaseService::class);
        $this->coin = self::getContainer()->get(CoinMockResponses::class);

        $extension = new Extension()->setName('Purchase test ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $card = new Card()->setName('Purchase card')->setDescription('Test')->setStatus(CardStatusEnum::PUBLISHED)->setRarity(CardRarityEnum::COMMON)->setExtension($extension);
        $card->setImageName('default_card.png');
        $this->booster = new Booster()
            ->setExtension($extension)
            ->setPurchasable(true)
            ->setPurchasePrice(10)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $this->booster->setImageName('default_card.png');
        $this->user = new DiscordUser()->setDiscordId('purchase-test-' . uniqid())->setUsername('Purchaser');

        foreach ([$extension, $card, $this->booster, $this->user] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function testNominalPurchaseCreditsTheBoosterAndPaysTheBank(): void
    {
        $purchase = $this->service->purchase($this->user, $this->booster, 'player-jwt');

        $this->assertSame(BoosterPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertSame(CoinMockResponses::TRANSACTION_ID, $purchase->getCoinTransactionId());
        $this->assertSame(1, $this->ownedQuantity());

        $post = $this->postedTransaction();
        $this->assertSame(['amount' => '1000000000', 'walletFrom' => '/api/wallets/' . CoinMockResponses::USER_WALLET_ID, 'walletTo' => '/api/wallets/' . CoinMockResponses::BANK_WALLET_ID, 'type' => 'purchase', 'externalIdentifier' => 'ytcg:booster-purchase:' . $purchase->getId(), 'description' => $this->booster->getDisplayName()], $post['body']);
        $this->assertContains('X-Player-Token: player-jwt', $post['headers']);
        $this->assertSame('inventory-changed', self::getContainer()->get(SpyHub::class)->getEvents()[0]['type']);
    }

    public function testOnlyOnePurchasePerParisDay(): void
    {
        $this->service->purchase($this->user, $this->booster, 'jwt');
        $requests = \count($this->coin->requests);

        try {
            $this->service->purchase($this->user, $this->booster, 'jwt');
            $this->fail('The second purchase of the day must be refused.');
        } catch (BoosterPurchaseRefusedException $exception) {
            $this->assertStringContainsString('déjà acheté', $exception->getUserMessage());
        }

        $this->assertCount($requests, $this->coin->requests, 'A refused purchase never reaches the coin.');
        $this->assertSame(1, $this->ownedQuantity());
    }

    public function testThePurchaseOfYesterdayParisDoesNotCount(): void
    {
        $this->persistPurchase(self::getContainer()->get(ParisDay::class)->start()->modify('-1 second'), BoosterPurchaseStatusEnum::COMPLETED);

        $this->assertSame(BoosterPurchaseStatusEnum::COMPLETED, $this->service->purchase($this->user, $this->booster, 'jwt')->getStatus());
    }

    public function testAPurchaseSinceParisMidnightCounts(): void
    {
        $this->persistPurchase(self::getContainer()->get(ParisDay::class)->start()->modify('+1 second'), BoosterPurchaseStatusEnum::COMPLETED);

        $this->expectException(BoosterPurchaseRefusedException::class);
        $this->service->purchase($this->user, $this->booster, 'jwt');
    }

    public function testAFailedPurchaseDoesNotConsumeTheQuota(): void
    {
        $this->persistPurchase(new \DateTimeImmutable(), BoosterPurchaseStatusEnum::FAILED);

        $this->assertSame(BoosterPurchaseStatusEnum::COMPLETED, $this->service->purchase($this->user, $this->booster, 'jwt')->getStatus());
    }

    #[DataProvider('refusals')]
    public function testAClearRefusalFailsThePurchaseAndCreditsNothing(int $status, string $message): void
    {
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('{}', ['http_code' => $status]));

        try {
            $this->service->purchase($this->user, $this->booster, 'jwt');
            $this->fail('The purchase must be refused.');
        } catch (BoosterPurchaseRefusedException $exception) {
            $this->assertStringContainsString($message, $exception->getUserMessage());
        }

        $this->assertSame(0, $this->ownedQuantity());
        $this->assertSame(BoosterPurchaseStatusEnum::FAILED, $this->onlyPurchase()->getStatus());
        $this->assertSame(1, $this->service->getRemainingPurchases($this->user));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'insufficient funds' => [422, 'Solde Youl Coin insuffisant'];
        yield 'wrong player token' => [403, 'reconnecte-toi'];
        yield 'idempotency conflict' => [409, 'refusé'];
    }

    public function testAnUnreachableCoinFailsThePurchaseWithoutSendingAnything(): void
    {
        $this->coin->override('GET', '/api/bank/wallet', static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        try {
            $this->service->purchase($this->user, $this->booster, 'jwt');
            $this->fail('The purchase must be refused.');
        } catch (BoosterPurchaseRefusedException $exception) {
            $this->assertStringContainsString('indisponible', $exception->getUserMessage());
        }

        $this->assertNull($this->postedTransaction(false));
        $this->assertSame(BoosterPurchaseStatusEnum::FAILED, $this->onlyPurchase()->getStatus());
    }

    public function testATimeoutLeavesThePurchasePendingThenReconciliationCompletesItOnce(): void
    {
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));

        $purchase = $this->service->purchase($this->user, $this->booster, 'jwt');

        $this->assertTrue($purchase->isPending());
        $this->assertSame(0, $this->ownedQuantity());
        $this->assertSame(0, $this->service->getRemainingPurchases($this->user), 'A pending purchase holds the daily quota.');

        $this->coin->override('GET', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['hydra:member' => [['id' => 'tx-found']]]));
        $this->service->reconcilePending();
        $this->service->reconcilePending();

        $this->assertSame(BoosterPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertSame('tx-found', $purchase->getCoinTransactionId());
        $this->assertSame(1, $this->ownedQuantity());
    }

    public function testAServerErrorLeavesThePurchasePending(): void
    {
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['http_code' => 502]));

        $this->assertTrue($this->service->purchase($this->user, $this->booster, 'jwt')->isPending());
    }

    public function testAPendingPurchaseWithoutTransactionIsAbandonedAfterTheDelayOnly(): void
    {
        $recent = $this->persistPurchase(new \DateTimeImmutable('-2 minutes'), BoosterPurchaseStatusEnum::PENDING);

        $this->service->reconcilePending($this->user);
        $this->assertTrue($recent->isPending());

        $old = $this->persistPurchase(new \DateTimeImmutable('-11 minutes'), BoosterPurchaseStatusEnum::PENDING);
        $this->service->reconcilePending($this->user);

        $this->assertSame(BoosterPurchaseStatusEnum::FAILED, $old->getStatus());
        $this->assertTrue($recent->isPending());
        $this->assertSame(0, $this->ownedQuantity());
    }

    public function testAnUnreachableCoinKeepsThePurchasePending(): void
    {
        $old = $this->persistPurchase(new \DateTimeImmutable('-2 hours'), BoosterPurchaseStatusEnum::PENDING);
        $this->coin->override('GET', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        $this->service->reconcilePending();

        $this->assertTrue($old->isPending());
    }

    public function testAResolvedPurchaseIsNeverCreditedAgain(): void
    {
        $purchase = $this->service->purchase($this->user, $this->booster, 'jwt');
        $this->coin->override('GET', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['hydra:member' => [['id' => 'tx']]]));

        $this->service->reconcilePending();

        $this->assertSame(BoosterPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertSame(1, $this->ownedQuantity());
    }

    public function testABoosterNotOnSaleIsRefused(): void
    {
        $this->booster->setPurchasable(false);
        $this->entityManager->flush();

        $this->assertRefusedWithoutPurchase();
    }

    public function testAnUndistributableBoosterIsRefused(): void
    {
        $this->booster->getExtension()->setStatus(ExtensionStatusEnum::DRAFT);
        $this->entityManager->flush();

        $this->assertRefusedWithoutPurchase();
    }

    private function assertRefusedWithoutPurchase(): void
    {
        try {
            $this->service->purchase($this->user, $this->booster, 'jwt');
            $this->fail('The purchase must be refused.');
        } catch (BoosterPurchaseRefusedException) {
            $this->assertSame([], $this->entityManager->getRepository(BoosterPurchase::class)->findBy(['discordUser' => $this->user]));
            $this->assertSame([], $this->coin->requests);
        }
    }

    private function persistPurchase(\DateTimeImmutable $requestedAt, BoosterPurchaseStatusEnum $status): BoosterPurchase
    {
        // Doctrine stores datetimes as given: UTC like the production clock
        $purchase = new BoosterPurchase($this->user, $this->booster, 10, $requestedAt->setTimezone(new \DateTimeZone('UTC')));
        $this->entityManager->persist($purchase);
        $this->entityManager->flush();

        if (BoosterPurchaseStatusEnum::FAILED === $status) {
            $purchase->fail('test', $requestedAt);
        } elseif (BoosterPurchaseStatusEnum::COMPLETED === $status) {
            $purchase->complete('tx-old', $requestedAt);
        }
        $this->entityManager->flush();

        return $purchase;
    }

    private function onlyPurchase(): BoosterPurchase
    {
        $purchases = $this->entityManager->getRepository(BoosterPurchase::class)->findBy(['discordUser' => $this->user]);
        $this->assertCount(1, $purchases);

        return $purchases[0];
    }

    private function ownedQuantity(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM user_booster WHERE discord_user_id = ? AND booster_id = ?',
            [$this->user->getDiscordId(), (string) $this->booster->getId()],
        );
    }

    /**
     * @return array{body: array<string, mixed>, headers: list<string>}|null
     */
    private function postedTransaction(bool $required = true): ?array
    {
        foreach ($this->coin->requests as $request) {
            if ('POST' === $request['method']) {
                return ['body' => json_decode((string) $request['options']['body'], true, flags: \JSON_THROW_ON_ERROR), 'headers' => $request['options']['headers']];
            }
        }

        $required && $this->fail('No debit was sent to the coin.');

        return null;
    }
}
