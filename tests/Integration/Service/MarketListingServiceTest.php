<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\TradeLineRequest;
use App\Entity\MarketListing;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Exception\Market\MarketListingRefusedException;
use App\Exception\Trade\InvalidTradeOfferException;
use App\Service\Market\MarketListingService;
use App\Service\Trade\EngagedCopies;
use App\Service\Trade\TradeOfferService;
use App\Tests\FeatureFlagTrait;
use App\Tests\Support\CoinMockResponses;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MarketListingServiceTest extends KernelTestCase
{
    use FeatureFlagTrait;
    use MarketTestTrait;

    private MarketListingService $service;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(MarketListingService::class);
        $this->bootMarketFixtures();
    }

    public function testAListingIsActiveAndHoldsOneCopy(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Listed');
        $this->giveCards($seller, $card, 3);

        $listing = $this->service->create($seller, $card, false, 25);

        $this->assertSame(MarketListingStatusEnum::ACTIVE, $listing->getStatus());
        $this->assertSame(25, $listing->getPrice());
        $this->assertFalse($listing->isHolo());
        $this->assertSame(['normal' => 1, 'holo' => 0], self::getContainer()->get(EngagedCopies::class)->reservedQuantities($seller)[(string) $card->getId()]);
    }

    public function testASellerWithoutCoinWalletCannotList(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Listed');
        $this->giveCards($seller, $card, 1);
        self::getContainer()->get(CoinMockResponses::class)->override('GET', '/api/user/' . $seller->getDiscordId() . '/wallet', static fn (): MockResponse => new MockResponse('', ['http_code' => 404]));

        $this->assertRefusal(fn () => $this->service->create($seller, $card, false, 5), 'connecte-toi une fois sur Youl Coin');
        $this->assertSame([], $this->entityManager->getRepository(MarketListing::class)->findAll());
    }

    public function testAnUnreachableCoinRefusesTheListing(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Listed');
        $this->giveCards($seller, $card, 1);
        self::getContainer()->get(CoinMockResponses::class)->override('GET', '/api/user/' . $seller->getDiscordId() . '/wallet', static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        $this->assertRefusal(fn () => $this->service->create($seller, $card, false, 5), 'indisponible');
    }

    public function testTheLastCopyCanBeListed(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Last');
        $this->giveCards($seller, $card, 1);

        $this->assertTrue($this->service->create($seller, $card, false, 5)->isActive());
    }

    public function testAHoloListingNeedsAHoloCopy(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Plain');
        $this->giveCards($seller, $card, 2);

        $this->assertRefusal(fn () => $this->service->create($seller, $card, true, 5), 'exemplaire holo');

        $holoCard = $this->createCard('Holo');
        $this->giveCards($seller, $holoCard, 1, 1);
        $this->assertRefusal(fn () => $this->service->create($seller, $holoCard, false, 5), 'exemplaire normal');
        $this->assertTrue($this->service->create($seller, $holoCard, true, 5)->isHolo());
    }

    public function testACardNotOwnedCannotBeListed(): void
    {
        $seller = $this->createUser('seller');

        $this->assertRefusal(fn () => $this->service->create($seller, $this->createCard('Nope'), false, 5), 'ne possèdes pas');
    }

    #[DataProvider('invalidPrices')]
    public function testThePriceIsAWholePositiveNumberOfCoinsWithACeiling(int $price): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Priced');
        $this->giveCards($seller, $card, 1);

        $this->assertRefusal(fn () => $this->service->create($seller, $card, false, $price), 'prix');
        $this->assertSame([], $this->entityManager->getRepository(MarketListing::class)->findBy(['seller' => $seller]));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPrices(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
        yield 'above the ceiling' => [MarketListingService::MAX_PRICE + 1];
    }

    public function testAnUnpublishedCardCannotBeListed(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Hidden');
        $this->giveCards($seller, $card, 1);
        $this->extension->setStatus(ExtensionStatusEnum::DRAFT);
        $this->entityManager->flush();

        $this->assertRefusal(fn () => $this->service->create($seller, $card, false, 5), 'catalogue publié');
    }

    public function testAtMostThreeActiveListingsAtOnce(): void
    {
        $seller = $this->createUser('seller');
        $listings = [];
        for ($i = 0; $i < 3; ++$i) {
            $card = $this->createCard('Quota ' . $i);
            $this->giveCards($seller, $card, 1);
            $listings[] = $this->service->create($seller, $card, false, 5);
        }

        $extra = $this->createCard('Quota extra');
        $this->giveCards($seller, $extra, 1);
        $this->assertRefusal(fn () => $this->service->create($seller, $extra, false, 5), '3 annonces');

        $this->service->withdraw($seller, $listings[0]);
        $this->assertTrue($this->service->create($seller, $extra, false, 5)->isActive(), 'A withdrawn listing frees its slot.');
    }

    public function testASoldListingFreesItsSlot(): void
    {
        $seller = $this->createUser('seller');
        $cards = [];
        for ($i = 0; $i < 3; ++$i) {
            $cards[$i] = $this->createCard('Slot ' . $i);
            $this->giveCards($seller, $cards[$i], 1);
            $listing = $this->service->create($seller, $cards[$i], false, 5);
        }
        $listing->close(MarketListingStatusEnum::SOLD, new \DateTimeImmutable());
        $this->entityManager->flush();

        $again = $this->createCard('Slot again');
        $this->giveCards($seller, $again, 1);
        $this->assertTrue($this->service->create($seller, $again, false, 5)->isActive());
    }

    public function testACopyCannotBeListedTwice(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Single');
        $this->giveCards($seller, $card, 1);
        $this->service->create($seller, $card, false, 5);

        $this->assertRefusal(fn () => $this->service->create($seller, $card, false, 5), 'déjà engagés');
    }

    public function testTwoCopiesCanBeListedSeparatelyButNotThree(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Double');
        $this->giveCards($seller, $card, 2);

        $this->service->create($seller, $card, false, 5);
        $this->service->create($seller, $card, false, 6);

        $this->assertRefusal(fn () => $this->service->create($seller, $card, false, 7), 'déjà engagés');
    }

    public function testAUniqueCanOnlyBeListedByItsHolder(): void
    {
        $holder = $this->createUser('holder');
        $other = $this->createUser('other');
        $unique = $this->createCard('One of one', unique: true, claimedBy: $holder);
        $this->giveCards($holder, $unique, 1);
        $this->giveCards($other, $unique, 1);

        $this->assertRefusal(fn () => $this->service->create($other, $unique, false, 5), 'ne t\'appartient pas');
        $this->assertTrue($this->service->create($holder, $unique, false, 500)->isActive());
    }

    public function testThePriceCanBeChangedByItsSellerOnly(): void
    {
        $seller = $this->createUser('seller');
        $other = $this->createUser('other');
        $card = $this->createCard('Repriced');
        $this->giveCards($seller, $card, 1);
        $listing = $this->service->create($seller, $card, false, 5);

        $this->assertRefusal(fn () => $this->service->changePrice($other, $listing, 1), 'ne t\'appartient pas');
        $this->assertRefusal(fn () => $this->service->changePrice($seller, $listing, 0), 'prix');

        $this->service->changePrice($seller, $listing, 42);
        $this->assertSame(42, $listing->getPrice());
    }

    public function testWithdrawingClosesTheListingAndReleasesTheCopy(): void
    {
        $seller = $this->createUser('seller');
        $other = $this->createUser('other');
        $card = $this->createCard('Withdrawn');
        $this->giveCards($seller, $card, 1);
        $listing = $this->service->create($seller, $card, false, 5);

        $this->assertRefusal(fn () => $this->service->withdraw($other, $listing), 'ne t\'appartient pas');

        $this->service->withdraw($seller, $listing);
        $this->assertSame(MarketListingStatusEnum::WITHDRAWN, $listing->getStatus());
        $this->assertNotNull($listing->getClosedAt());
        $this->assertArrayNotHasKey((string) $card->getId(), self::getContainer()->get(EngagedCopies::class)->reservedQuantities($seller));

        $this->assertRefusal(fn () => $this->service->withdraw($seller, $listing), 'plus en vente');
        $this->assertRefusal(fn () => $this->service->changePrice($seller, $listing, 9), 'plus en vente');
    }

    public function testAListingWithAPurchaseInProgressCannotBeChanged(): void
    {
        $seller = $this->createUser('seller');
        $card = $this->createCard('Busy');
        $this->giveCards($seller, $card, 1);
        $listing = $this->service->create($seller, $card, false, 5);
        $listing->reserveForPurchase();
        $this->entityManager->flush();

        $this->assertRefusal(fn () => $this->service->changePrice($seller, $listing, 9), 'achat est en cours');
        $this->assertRefusal(fn () => $this->service->withdraw($seller, $listing), 'achat est en cours');
        $this->assertSame(5, $listing->getPrice());
    }

    public function testACopyOfferedInAPendingTradeCannotBeListed(): void
    {
        $seller = $this->createUser('seller');
        $other = $this->createUser('other');
        $card = $this->createCard('Offered');
        $wanted = $this->createCard('Wanted');
        $this->giveCards($seller, $card, 1);
        $this->giveCards($other, $wanted, 1);
        self::getContainer()->get(TradeOfferService::class)->create($seller, $other, [new TradeLineRequest($card, 1)], [new TradeLineRequest($wanted, 1)]);

        $this->assertRefusal(fn () => $this->service->create($seller, $card, false, 5), 'déjà engagés');
    }

    public function testAListedCopyCannotBeOfferedInATrade(): void
    {
        $seller = $this->createUser('seller');
        $other = $this->createUser('other');
        $card = $this->createCard('Listed first');
        $wanted = $this->createCard('Wanted');
        $this->giveCards($seller, $card, 1);
        $this->giveCards($other, $wanted, 1);
        $this->service->create($seller, $card, false, 5);

        $this->expectException(InvalidTradeOfferException::class);
        $this->expectExceptionMessage('already engaged');
        self::getContainer()->get(TradeOfferService::class)->create($seller, $other, [new TradeLineRequest($card, 1)], [new TradeLineRequest($wanted, 1)]);
    }

    public function testTheReservationLedgerDoesNotDependOnTheTradesFeatureFlag(): void
    {
        $seller = $this->createUser('seller');
        $other = $this->createUser('other');
        $card = $this->createCard('Ledger');
        $wanted = $this->createCard('Wanted');
        $this->giveCards($seller, $card, 2);
        $this->giveCards($other, $wanted, 1);
        $trades = self::getContainer()->get(TradeOfferService::class);
        $trades->create($seller, $other, [new TradeLineRequest($card, 1)], [new TradeLineRequest($wanted, 1)]);
        $this->service->create($seller, $card, false, 5);

        $this->setFeature(FeatureEnum::TRADES, false);

        $ledger = self::getContainer()->get(EngagedCopies::class);
        $this->assertSame(['normal' => 2, 'holo' => 0], $ledger->reservedQuantities($seller)[(string) $card->getId()]);
        $this->assertArrayHasKey((string) $card->getId(), $ledger->engagedCardIds($seller));
        $this->assertRefusal(fn () => $this->service->create($seller, $card, false, 5), 'déjà engagés');
    }

    public function testTradesAllowGivingTheLastCopy(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $last = $this->createCard('Last copy');
        $wanted = $this->createCard('Wanted');
        $this->giveCards($alice, $last, 1);
        $this->giveCards($bob, $wanted, 1);
        $trades = self::getContainer()->get(TradeOfferService::class);

        $offer = $trades->create($alice, $bob, [new TradeLineRequest($last, 1)], [new TradeLineRequest($wanted, 1)]);
        $trades->accept($offer, $bob);

        $this->assertSame([0, 0], $this->owned($alice, $last));
        $this->assertSame([1, 0], $this->owned($bob, $last));
    }

    private function assertRefusal(\Closure $action, string $userMessagePart): void
    {
        try {
            $action();
            $this->fail('The action must be refused.');
        } catch (MarketListingRefusedException $exception) {
            $this->assertStringContainsString($userMessagePart, $exception->getUserMessage());
        }
    }
}
