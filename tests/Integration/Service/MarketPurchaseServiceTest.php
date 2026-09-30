<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Card;
use App\Entity\CoinSettings;
use App\Entity\DiscordUser;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Entity\Notification;
use App\Entity\UniverseCompletionReward;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Exception\Market\MarketPurchaseRefusedException;
use App\Service\Market\MarketListingService;
use App\Service\Market\MarketPurchaseService;
use App\Tests\Support\CoinMockResponses;
use App\Tests\Support\SpyHub;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MarketPurchaseServiceTest extends KernelTestCase
{
    use MarketTestTrait;

    private MarketPurchaseService $service;

    private CoinMockResponses $coin;

    private DiscordUser $seller;

    private DiscordUser $buyer;

    private Card $card;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('cache.app')->clear();
        $this->service = self::getContainer()->get(MarketPurchaseService::class);
        $this->coin = self::getContainer()->get(CoinMockResponses::class);
        $this->bootMarketFixtures();

        $this->seller = $this->createUser('seller');
        $this->buyer = $this->createUser('buyer');
        $this->card = $this->createCard('Sold card');
        $this->giveCards($this->seller, $this->card, 2);
    }

    public function testNominalPurchaseMovesTheCardAndPaysThroughTheBank(): void
    {
        $listing = $this->list(100);

        $purchase = $this->service->purchase($this->buyer, $listing, 'buyer-jwt');

        $this->assertSame(MarketPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertSame(MarketListingStatusEnum::SOLD, $listing->getStatus());
        $this->assertSame([1, 0], $this->owned($this->seller, $this->card));
        $this->assertSame([1, 0], $this->owned($this->buyer, $this->card));

        [$payment, $payout] = $this->postedTransactions();
        $this->assertSame('market_payment', $payment['body']['type']);
        $this->assertSame('ytcg:market-payment:' . $purchase->getId(), $payment['body']['externalIdentifier']);
        $this->assertSame('10000000000', $payment['body']['amount']);
        $this->assertSame('/api/wallets/' . CoinMockResponses::USER_WALLET_ID, $payment['body']['walletFrom']);
        $this->assertContains('X-Player-Token: buyer-jwt', $payment['headers']);
        $this->assertSame('market_payout', $payout['body']['type']);
        $this->assertSame('ytcg:market-payout:' . $purchase->getId(), $payout['body']['externalIdentifier']);
        $this->assertSame('/api/wallets/' . CoinMockResponses::BANK_WALLET_ID, $payout['body']['walletFrom']);
        $this->assertSame(CoinMockResponses::TRANSACTION_ID, $purchase->getPaymentTransactionId());
        $this->assertSame(CoinMockResponses::TRANSACTION_ID, $purchase->getPayoutTransactionId());
    }

    public function testNumericDiscordIdsWorkLikeAnyOther(): void
    {
        // PHP turns numeric array keys into ints: real Discord ids must not break the lock ordering
        $seller = new DiscordUser()->setDiscordId((string) random_int(100_000_000_000_000_000, 199_999_999_999_999_999))->setUsername('Numeric seller');
        $buyer = new DiscordUser()->setDiscordId((string) random_int(200_000_000_000_000_000, 299_999_999_999_999_999))->setUsername('Numeric buyer');
        $this->entityManager->persist($seller);
        $this->entityManager->persist($buyer);
        $this->entityManager->flush();
        $card = $this->createCard('Numeric');
        $this->giveCards($seller, $card, 1);

        $this->service->purchase($buyer, $this->createListing($seller, $card, false, 5), 'jwt');

        $this->assertSame([0, 0], $this->owned($seller, $card));
        $this->assertSame([1, 0], $this->owned($buyer, $card));
    }

    public function testTheSellerReceivesThePriceMinusTheCommission(): void
    {
        $purchase = $this->service->purchase($this->buyer, $this->list(100), 'jwt');

        $this->assertSame('500000000', $purchase->getFeeMinor());
        $this->assertSame('9500000000', $this->postedTransactions()[1]['body']['amount']);
    }

    public function testTheCommissionFollowsTheAdminSettingAndIsFrozenOnThePurchase(): void
    {
        $settings = $this->entityManager->find(CoinSettings::class, CoinSettings::ID);
        $settings->setMarketFeePercent(12);
        $this->entityManager->flush();

        $purchase = $this->service->purchase($this->buyer, $this->list(50), 'jwt');
        $settings->setMarketFeePercent(5);
        $this->entityManager->flush();

        $this->assertSame('600000000', $purchase->getFeeMinor());
        $this->assertSame('4400000000', $this->postedTransactions()[1]['body']['amount']);
    }

    #[DataProvider('fees')]
    public function testTheFeeIsComputedInMinorUnitsWithIntegerMath(int $price, int $percent, int $expected): void
    {
        $this->assertSame($expected, MarketPurchaseService::feeMinor($price, $percent));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function fees(): iterable
    {
        yield 'one coin at 5 %' => [1, 5, 5_000_000];
        yield 'no fee' => [100, 0, 0];
        yield 'everything' => [100, 100, 10_000_000_000];
        yield 'ceiling price at 100 %' => [10_000_000, 100, 1_000_000_000_000_000];
    }

    public function testAFullCommissionSkipsThePayout(): void
    {
        $this->entityManager->find(CoinSettings::class, CoinSettings::ID)->setMarketFeePercent(100);
        $this->entityManager->flush();

        $purchase = $this->service->purchase($this->buyer, $this->list(10), 'jwt');

        $this->assertSame(MarketPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertCount(1, $this->postedTransactions());
        $this->assertNull($purchase->getPayoutTransactionId());
    }

    public function testTheLastCopyCanBeSold(): void
    {
        $seller = $this->createUser('lastseller');
        $card = $this->createCard('Only one');
        $this->giveCards($seller, $card, 1);
        $listing = $this->entityManager->getRepository(MarketListing::class)->find($this->createListing($seller, $card, false, 5)->getId());

        $this->service->purchase($this->buyer, $listing, 'jwt');

        $this->assertSame([0, 0], $this->owned($seller, $card));
        $this->assertSame([1, 0], $this->owned($this->buyer, $card));
    }

    public function testAHoloSaleMovesTheHoloCopyAndKeepsTheInvariants(): void
    {
        $card = $this->createCard('Holo sale');
        $this->giveCards($this->seller, $card, 3, 1);
        $listing = $this->createListing($this->seller, $card, true, 30);
        $this->giveCards($this->buyer, $card, 1, 0);

        $this->service->purchase($this->buyer, $listing, 'jwt');

        $this->assertSame([2, 0], $this->owned($this->seller, $card));
        $this->assertSame([2, 1], $this->owned($this->buyer, $card));
    }

    public function testAUniqueChangesHolderAndTheSellerKeepsNothing(): void
    {
        $unique = $this->createCard('Mythic', unique: true, claimedBy: $this->seller);
        $this->giveCards($this->seller, $unique, 1);
        $listing = $this->createListing($this->seller, $unique, false, 999);

        $this->service->purchase($this->buyer, $listing, 'jwt');

        $this->entityManager->refresh($unique);
        $this->assertSame($this->buyer->getDiscordId(), $unique->getClaimedBy()?->getDiscordId());
        $this->assertSame([0, 0], $this->owned($this->seller, $unique));
        $this->assertSame([1, 0], $this->owned($this->buyer, $unique));
    }

    public function testTheSellerIsNotifiedAndBothInventoriesAreRefreshed(): void
    {
        $this->service->purchase($this->buyer, $this->list(1500), 'jwt');

        $notifications = $this->entityManager->getRepository(Notification::class)->findBy(['recipient' => $this->seller->getDiscordId(), 'type' => NotificationTypeEnum::MARKET_SOLD]);
        $this->assertCount(1, $notifications);
        $this->assertSame(['buyerName' => 'Buyer', 'cardName' => $this->card->getName(), 'price' => 1500], $notifications[0]->getPayload());
        $this->assertNull($notifications[0]->getReadAt(), 'The sale happens while the seller is away.');

        $inventoryEvents = array_filter(self::getContainer()->get(SpyHub::class)->getEvents(), static fn (array $event): bool => 'inventory-changed' === $event['type']);
        $this->assertCount(2, $inventoryEvents);
    }

    public function testTheBuyerUniverseCompletionIsChecked(): void
    {
        $extra = $this->createCard('Completing');
        $this->giveCards($this->seller, $extra, 1);
        $listing = $this->createListing($this->seller, $extra, false, 5);
        // the buyer already owns every other card of the universe
        foreach ($this->entityManager->getRepository(Card::class)->findBy(['extension' => $this->extension]) as $card) {
            if ($card->getId() !== $extra->getId()) {
                $this->giveCards($this->buyer, $card, 1);
            }
        }

        $this->service->purchase($this->buyer, $listing, 'jwt');

        $this->assertCount(1, $this->entityManager->getRepository(UniverseCompletionReward::class)->findBy(['discordUser' => $this->buyer]));
    }

    public function testABuyerCannotBuyTheirOwnListing(): void
    {
        $listing = $this->list(10);
        $requests = \count($this->coin->requests);

        $this->assertRefusal(fn () => $this->service->purchase($this->seller, $listing, 'jwt'), 'propre annonce');

        $this->assertCount($requests, $this->coin->requests, 'A refused purchase never reaches the coin.');
        $this->assertSame(MarketListingStatusEnum::ACTIVE, $listing->getStatus());
        $this->assertSame([], $this->entityManager->getRepository(MarketPurchase::class)->findAll());
    }

    public function testASellerWithoutCoinWalletCannotBePurchasedFrom(): void
    {
        $listing = $this->list(10);
        $this->coin->override('GET', '/api/user/' . $this->seller->getDiscordId() . '/wallet', static fn (): MockResponse => new MockResponse('', ['http_code' => 404]));

        $this->assertRefusal(fn () => $this->service->purchase($this->buyer, $listing, 'jwt'), 'ne peut pas encore recevoir de Youl Coin');

        $this->assertSame([], $this->postedTransactions());
        $this->assertSame([], $this->entityManager->getRepository(MarketPurchase::class)->findAll());
        $this->assertSame(MarketListingStatusEnum::ACTIVE, $listing->getStatus());
        $this->assertSame([2, 0], $this->owned($this->seller, $this->card));
    }

    public function testAnUnreachableCoinRefusesBeforeCheckingTheSellerWalletCreatesAnything(): void
    {
        $listing = $this->list(10);
        $this->coin->override('GET', '/api/user/' . $this->seller->getDiscordId() . '/wallet', static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        $this->assertRefusal(fn () => $this->service->purchase($this->buyer, $listing, 'jwt'), 'indisponible');

        $this->assertSame([], $this->postedTransactions());
        $this->assertSame([], $this->entityManager->getRepository(MarketPurchase::class)->findAll());
    }

    public function testASoldListingIsUnavailableToTheNextBuyer(): void
    {
        $listing = $this->list(10);
        $second = $this->createUser('second');
        $this->service->purchase($this->buyer, $listing, 'jwt');
        $transactions = \count($this->postedTransactions());

        $this->assertRefusal(fn () => $this->service->purchase($second, $listing, 'jwt'), 'plus disponible');

        $this->assertCount($transactions, $this->postedTransactions());
        $this->assertSame([1, 0], $this->owned($this->buyer, $this->card));
        $this->assertSame([0, 0], $this->owned($second, $this->card));
    }

    public function testWhileThePaymentIsUnconfirmedTheListingIsUnavailableToOthers(): void
    {
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));
        $listing = $this->list(10);
        $second = $this->createUser('second');

        $purchase = $this->service->purchase($this->buyer, $listing, 'jwt');

        $this->assertSame(MarketPurchaseStatusEnum::PAYMENT_PENDING, $purchase->getStatus());
        $this->assertSame(MarketListingStatusEnum::RESERVED_FOR_PURCHASE, $listing->getStatus());
        $this->assertSame([2, 0], $this->owned($this->seller, $this->card), 'No card moves before the payment is confirmed.');
        $this->assertRefusal(fn () => $this->service->purchase($second, $listing, 'jwt'), 'plus disponible');
    }

    #[DataProvider('refusals')]
    public function testAClearRefusalFailsThePurchaseAndReopensTheListing(int $status, string $message): void
    {
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('{}', ['http_code' => $status]));
        $listing = $this->list(10);

        $this->assertRefusal(fn () => $this->service->purchase($this->buyer, $listing, 'jwt'), $message);

        $purchase = $this->onlyPurchase();
        $this->assertSame(MarketPurchaseStatusEnum::FAILED, $purchase->getStatus());
        $this->assertSame(MarketListingStatusEnum::ACTIVE, $listing->getStatus());
        $this->assertSame([2, 0], $this->owned($this->seller, $this->card));
        $this->assertSame([0, 0], $this->owned($this->buyer, $this->card));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'insufficient funds' => [422, 'Solde Youl Coin insuffisant'];
        yield 'wrong player token' => [403, 'reconnecte-toi'];
        yield 'idempotency conflict' => [409, 'déjà été enregistré'];
    }

    public function testAnUnreachableCoinFailsThePurchaseWithoutSendingAnything(): void
    {
        $this->coin->override('GET', '/api/bank/wallet', static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));
        $listing = $this->list(10);

        $this->assertRefusal(fn () => $this->service->purchase($this->buyer, $listing, 'jwt'), 'indisponible');

        $this->assertSame([], $this->postedTransactions());
        $this->assertSame(MarketPurchaseStatusEnum::FAILED, $this->onlyPurchase()->getStatus());
        $this->assertSame(MarketListingStatusEnum::ACTIVE, $listing->getStatus());
    }

    public function testReconciliationFindsTheLatePaymentAndCompletesTheSaleOnce(): void
    {
        $listing = $this->list(100);
        $purchase = $this->uncertainPurchase($listing);

        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx-payout'], 201));
        $this->coin->override('GET', '/api/transactions', $this->transactionFound('tx-payment', 'ytcg:market-payment:' . $purchase->getId()));
        $this->service->reconcile();
        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertSame('tx-payment', $purchase->getPaymentTransactionId());
        $this->assertSame(MarketListingStatusEnum::SOLD, $listing->getStatus());
        $this->assertSame([1, 0], $this->owned($this->seller, $this->card));
        $this->assertSame([1, 0], $this->owned($this->buyer, $this->card));
        $payouts = array_filter($this->postedTransactions(), static fn (array $post): bool => 'market_payout' === $post['body']['type']);
        $this->assertCount(1, $payouts, 'The seller is paid exactly once.');
    }

    public function testAnUnknownPaymentIsAbandonedOnlyAfterTheDelay(): void
    {
        $listing = $this->list(100);
        $purchase = $this->uncertainPurchase($listing);

        $this->service->reconcile();
        $this->assertSame(MarketPurchaseStatusEnum::PAYMENT_PENDING, $purchase->getStatus(), 'Too recent: the debit may still land.');

        $this->age($purchase, '-11 minutes');
        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::FAILED, $purchase->getStatus());
        $this->assertSame(MarketListingStatusEnum::ACTIVE, $listing->getStatus());
        $this->assertSame([2, 0], $this->owned($this->seller, $this->card));
    }

    public function testAnUnreachableCoinKeepsAnOldPendingPurchasePending(): void
    {
        $purchase = $this->uncertainPurchase($this->list(100));
        $this->age($purchase, '-2 hours');
        $this->coin->override('GET', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::PAYMENT_PENDING, $purchase->getStatus());
    }

    public function testAFailedPaymentIsNeverResurrectedByTheReconciliation(): void
    {
        $listing = $this->list(100);
        $purchase = $this->uncertainPurchase($listing);
        $this->age($purchase, '-11 minutes');
        $this->service->reconcile();
        $this->assertSame(MarketPurchaseStatusEnum::FAILED, $purchase->getStatus());

        $this->coin->override('GET', '/api/transactions', $this->transactionFound('late', 'whatever'));
        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::FAILED, $purchase->getStatus());
        $this->assertSame([0, 0], $this->owned($this->buyer, $this->card));
    }

    public function testAnUncertainPayoutIsFoundAgainWithoutPayingTwice(): void
    {
        $this->coin->override('POST', '/api/transactions', $this->respondByType(['market_payout' => static fn (): MockResponse => new MockResponse('', ['http_code' => 502])]));
        $purchase = $this->service->purchase($this->buyer, $this->list(100), 'jwt');

        $this->assertSame(MarketPurchaseStatusEnum::CARD_TRANSFERRED, $purchase->getStatus());
        $this->assertSame([1, 0], $this->owned($this->buyer, $this->card), 'The card is delivered even while the seller payout is pending.');

        $posts = \count($this->postedTransactions());
        $this->coin->override('GET', '/api/transactions', $this->transactionFound('tx-payout', 'ytcg:market-payout:' . $purchase->getId()));
        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertSame('tx-payout', $purchase->getPayoutTransactionId());
        $this->assertCount($posts, $this->postedTransactions(), 'A payout that already landed is not sent again.');
    }

    public function testAMissingPayoutIsSentAgainWithTheSameIdentifier(): void
    {
        $this->coin->override('POST', '/api/transactions', $this->respondByType(['market_payout' => static fn (): MockResponse => new MockResponse('', ['error' => 'timeout'])]));
        $purchase = $this->service->purchase($this->buyer, $this->list(100), 'jwt');
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx-retried'], 201));

        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $payouts = array_values(array_filter($this->postedTransactions(), static fn (array $post): bool => 'market_payout' === $post['body']['type']));
        $this->assertCount(2, $payouts);
        $this->assertSame($payouts[0]['body']['externalIdentifier'], $payouts[1]['body']['externalIdentifier']);
        $this->assertSame($payouts[0]['body']['amount'], $payouts[1]['body']['amount']);
    }

    public function testARefusedPayoutStaysOwedAndIsRetriedLater(): void
    {
        $this->coin->override('POST', '/api/transactions', $this->respondByType(['market_payout' => static fn (): MockResponse => new MockResponse('{}', ['http_code' => 422])]));
        $purchase = $this->service->purchase($this->buyer, $this->list(100), 'jwt');
        $this->assertSame(MarketPurchaseStatusEnum::CARD_TRANSFERRED, $purchase->getStatus());

        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx-later'], 201));
        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::COMPLETED, $purchase->getStatus());
        $this->assertSame('tx-later', $purchase->getPayoutTransactionId());
    }

    public function testACompletedPurchaseIsNeverPaidAgain(): void
    {
        $this->service->purchase($this->buyer, $this->list(100), 'jwt');
        $posts = \count($this->postedTransactions());

        $this->service->reconcile();

        $this->assertCount($posts, $this->postedTransactions());
    }

    public function testASellerWhoLostTheCopyGetsTheBuyerRefunded(): void
    {
        $listing = $this->list(100);
        $this->entityManager->getConnection()->executeStatement('UPDATE user_card SET quantity = 0 WHERE discord_user_id = ? AND card_id = ?', [$this->seller->getDiscordId(), (string) $this->card->getId()]);

        $purchase = $this->service->purchase($this->buyer, $listing, 'jwt');

        $this->assertSame(MarketPurchaseStatusEnum::REFUNDED, $purchase->getStatus());
        $this->assertSame(MarketListingStatusEnum::INVALIDATED, $listing->getStatus());
        $this->assertSame([0, 0], $this->owned($this->buyer, $this->card));
        [$payment, $refund] = $this->postedTransactions();
        $this->assertSame('market_payment', $payment['body']['type']);
        $this->assertSame('market_refund', $refund['body']['type']);
        $this->assertSame('ytcg:market-refund:' . $purchase->getId(), $refund['body']['externalIdentifier']);
        $this->assertSame('10000000000', $refund['body']['amount']);
        $this->assertSame('/api/wallets/' . CoinMockResponses::USER_WALLET_ID, $refund['body']['walletTo']);
        $this->assertCount(2, $this->postedTransactions(), 'No payout when the card did not move.');
        $this->assertSame(CoinMockResponses::TRANSACTION_ID, $purchase->getRefundTransactionId());
    }

    public function testAnUncertainRefundIsRetriedByTheReconciliation(): void
    {
        $listing = $this->list(100);
        $this->entityManager->getConnection()->executeStatement('UPDATE user_card SET quantity = 0 WHERE discord_user_id = ? AND card_id = ?', [$this->seller->getDiscordId(), (string) $this->card->getId()]);
        $this->coin->override('POST', '/api/transactions', $this->respondByType(['market_refund' => static fn (): MockResponse => new MockResponse('', ['http_code' => 500])]));

        $purchase = $this->service->purchase($this->buyer, $listing, 'jwt');
        $this->assertSame(MarketPurchaseStatusEnum::REFUND_PENDING, $purchase->getStatus());

        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx-refund'], 201));
        $this->service->reconcile();
        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::REFUNDED, $purchase->getStatus());
        $this->assertSame('tx-refund', $purchase->getRefundTransactionId());
    }

    public function testAnUncertainRefundThatLandedIsFoundNotRepeated(): void
    {
        $listing = $this->list(100);
        $this->entityManager->getConnection()->executeStatement('UPDATE user_card SET quantity = 0 WHERE discord_user_id = ? AND card_id = ?', [$this->seller->getDiscordId(), (string) $this->card->getId()]);
        $this->coin->override('POST', '/api/transactions', $this->respondByType(['market_refund' => static fn (): MockResponse => new MockResponse('', ['error' => 'timeout'])]));
        $purchase = $this->service->purchase($this->buyer, $listing, 'jwt');
        $posts = \count($this->postedTransactions());

        $this->coin->override('GET', '/api/transactions', $this->transactionFound('tx-refund-found', 'ytcg:market-refund:' . $purchase->getId()));
        $this->service->reconcile();

        $this->assertSame(MarketPurchaseStatusEnum::REFUNDED, $purchase->getStatus());
        $this->assertCount($posts, $this->postedTransactions());
    }

    public function testAUniqueThatChangedHandsRefundsTheBuyer(): void
    {
        $unique = $this->createCard('Stolen', unique: true, claimedBy: $this->seller);
        $this->giveCards($this->seller, $unique, 1);
        $listing = $this->createListing($this->seller, $unique, false, 50);
        $this->entityManager->getConnection()->executeStatement('UPDATE card SET claimed_by = ? WHERE id = ?', [$this->buyer->getDiscordId(), (string) $unique->getId()]);

        $purchase = $this->service->purchase($this->createUser('other'), $listing, 'jwt');

        $this->assertSame(MarketPurchaseStatusEnum::REFUNDED, $purchase->getStatus());
        $this->assertSame([1, 0], $this->owned($this->seller, $unique), 'The seller row is left untouched.');
    }

    public function testReconciliationCanBeScopedToAPlayer(): void
    {
        $other = $this->createUser('other');
        $otherCard = $this->createCard('Other listing');
        $this->giveCards($other, $otherCard, 1);
        $mine = $this->uncertainPurchase($this->list(10));
        $theirs = $this->uncertainPurchase($this->createListing($other, $otherCard, false, 10), $this->createUser('otherbuyer'));
        $this->age($mine, '-11 minutes');
        $this->age($theirs, '-11 minutes');

        $this->service->reconcile($this->buyer);

        $this->assertSame(MarketPurchaseStatusEnum::FAILED, $mine->getStatus());
        $this->assertSame(MarketPurchaseStatusEnum::PAYMENT_PENDING, $theirs->getStatus());
    }

    private function list(int $price): MarketListing
    {
        return $this->createListing($this->seller, $this->card, false, $price);
    }

    private function createListing(DiscordUser $seller, Card $card, bool $holo, int $price): MarketListing
    {
        return self::getContainer()->get(MarketListingService::class)->create($seller, $card, $holo, $price);
    }

    private function uncertainPurchase(MarketListing $listing, ?DiscordUser $buyer = null): MarketPurchase
    {
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));
        $purchase = $this->service->purchase($buyer ?? $this->buyer, $listing, 'jwt');
        $this->assertSame(MarketPurchaseStatusEnum::PAYMENT_PENDING, $purchase->getStatus());

        return $purchase;
    }

    private function age(MarketPurchase $purchase, string $modifier): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE market_purchase SET requested_at = ? WHERE id = ?',
            [new \DateTimeImmutable($modifier, new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), (string) $purchase->getId()],
        );
        $this->entityManager->refresh($purchase);
    }

    private function onlyPurchase(): MarketPurchase
    {
        $purchases = $this->entityManager->getRepository(MarketPurchase::class)->findBy(['buyer' => $this->buyer]);
        $this->assertCount(1, $purchases);

        return $purchases[0];
    }

    private function assertRefusal(\Closure $action, string $userMessagePart): void
    {
        try {
            $action();
            $this->fail('The purchase must be refused.');
        } catch (MarketPurchaseRefusedException $exception) {
            $this->assertStringContainsString($userMessagePart, $exception->getUserMessage());
        }
    }

    /**
     * @param array<string, \Closure(): MockResponse> $overrides by transaction type; other types are accepted
     *
     * @return \Closure(): MockResponse
     */
    private function respondByType(array $overrides): \Closure
    {
        return function () use ($overrides): MockResponse {
            $posts = $this->postedTransactions();
            $type = $posts[array_key_last($posts)]['body']['type'];

            return isset($overrides[$type]) ? $overrides[$type]() : CoinMockResponses::json(['id' => CoinMockResponses::TRANSACTION_ID], 201);
        };
    }

    /**
     * @return \Closure(): MockResponse
     */
    private function transactionFound(string $transactionId, string $identifier): \Closure
    {
        return function () use ($transactionId, $identifier): MockResponse {
            $lookups = array_values(array_filter($this->coin->requests, static fn (array $request): bool => 'GET' === $request['method'] && '/api/transactions' === $request['path']));
            $asked = $lookups[array_key_last($lookups)]['options']['query']['externalIdentifier'] ?? null;

            return CoinMockResponses::json(['hydra:member' => $asked === $identifier || 'whatever' === $identifier ? [['id' => $transactionId]] : []]);
        };
    }

    /**
     * @return list<array{body: array<string, mixed>, headers: list<string>}>
     */
    private function postedTransactions(): array
    {
        $posts = [];
        foreach ($this->coin->requests as $request) {
            if ('POST' === $request['method'] && '/api/transactions' === $request['path']) {
                $posts[] = ['body' => json_decode((string) $request['options']['body'], true, flags: \JSON_THROW_ON_ERROR), 'headers' => $request['options']['headers']];
            }
        }

        return $posts;
    }
}
