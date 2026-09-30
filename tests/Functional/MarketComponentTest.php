<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Market\MarketPurchaseStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Service\Market\MarketListingService;
use App\Service\Market\MarketPurchaseService;
use App\Tests\Support\CoinMockResponses;
use App\Tests\Support\SpyHub;
use App\Twig\Components\MarketBoard;
use App\Twig\Components\MyShop;
use App\Twig\Components\RecycleHub;
use App\Twig\Components\TradeComposer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class MarketComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string BARLITO = '188967649332428800';

    private const string JUJU = '195659530363731968';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Extension $extension;

    private DiscordUser $buyer;

    private DiscordUser $seller;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.app')->clear();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->buyer = $this->authenticateClient($this->client, self::JUJU);
        $this->seller = $this->user(self::BARLITO);

        $this->extension = new Extension()->setName('Market UI extension ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($this->extension);
        $this->entityManager->persist($this->card('Filler unowned'));
        $this->entityManager->flush();
    }

    public function testTheBoardShowsOtherPlayersListingsInClearAndHidesMine(): void
    {
        $card = $this->card('Visible card');
        $this->listing($this->seller, $card, false, 40);
        $mine = $this->card('My own card');
        $this->listing($this->buyer, $mine, false, 15);

        $crawler = $this->board()->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="market-listing"]'));
        $text = $crawler->filter('[data-testid="market-listing"]')->text();
        $this->assertStringContainsString($card->getName(), $text);
        $this->assertStringContainsString('Barlito', $text);
        $this->assertStringContainsString('40 YLC', $text);
        $this->assertCount(0, $crawler->filter('[data-testid="masked-card"]'));
    }

    public function testAnEmptyBoardSaysSo(): void
    {
        $this->assertCount(1, $this->board()->render()->crawler()->filter('[data-testid="market-empty"]'));
    }

    public function testTheFiltersAndTheSortNarrowAndOrderTheBoard(): void
    {
        $rare = $this->card('Rare one', CardRarityEnum::RARE);
        $common = $this->card('Common one');
        $otherExtension = new Extension()->setName('Other market ext ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($otherExtension);
        $elsewhere = $this->card('Elsewhere', CardRarityEnum::COMMON, $otherExtension);
        $this->entityManager->flush();
        $this->listing($this->seller, $rare, true, 300);
        $this->listing($this->seller, $common, false, 20);
        $this->listing($this->seller, $elsewhere, false, 60);
        $board = $this->board();

        $names = static fn (TestLiveComponent $component): array => $component->render()->crawler()->filter('[data-testid="market-listing"] p.truncate.font-display')->each(static fn ($node): string => trim($node->text()));
        $this->assertCount(3, $names($board));

        $board->set('rarity', 'rare');
        $this->assertSame([$rare->getName()], $names($board));

        $board->set('rarity', '');
        $board->set('finish', 'normal');
        $this->assertCount(2, $names($board));
        $board->set('finish', 'holo');
        $this->assertSame([$rare->getName()], $names($board));

        $board->set('finish', '');
        $board->call('filterUniverse', ['slug' => $otherExtension->getSlug()]);
        $this->assertSame([$elsewhere->getName()], $names($board));

        $board->call('resetFilters');
        $board->set('sort', 'price_asc');
        $this->assertSame([$common->getName(), $elsewhere->getName(), $rare->getName()], $names($board));
        $board->set('sort', 'price_desc');
        $this->assertSame([$rare->getName(), $elsewhere->getName(), $common->getName()], $names($board));
    }

    public function testTheBoardIsPaginated(): void
    {
        for ($i = 0; $i < MarketBoard::PER_PAGE + 1; ++$i) {
            $this->listing($this->seller, $this->card('Paged ' . $i), false, 10 + $i);
        }
        $board = $this->board();

        $crawler = $board->render()->crawler();
        $this->assertCount(MarketBoard::PER_PAGE, $crawler->filter('[data-testid="market-listing"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="market-pagination"]'));

        $crawler = $board->call('goToPage', ['page' => 2])->render()->crawler();
        $this->assertCount(1, $crawler->filter('[data-testid="market-listing"]'));
        $this->assertNotNull($crawler->filter('[data-testid="market-next"]')->attr('disabled'));

        $crawler = $board->call('goToPage', ['page' => 99])->render()->crawler();
        $this->assertCount(1, $crawler->filter('[data-testid="market-listing"]'), 'An out-of-range page is clamped.');
    }

    public function testBuyingNeedsASecondConfirmingClickThenMovesTheCard(): void
    {
        $card = $this->card('Bought card');
        $listing = $this->listing($this->seller, $card, false, 25);
        $this->give($this->seller, $card, 1);
        $board = $this->board();

        $crawler = $board->call('askBuy', ['listingId' => (string) $listing->getId()])->render()->crawler();
        $this->assertCount(1, $crawler->filter('[data-testid="listing-confirm"]'));
        $this->assertSame(0, $this->purchaseCount());

        $crawler = $board->call('abortBuy')->render()->crawler();
        $this->assertCount(0, $crawler->filter('[data-testid="listing-confirm"]'));

        $board->call('askBuy', ['listingId' => (string) $listing->getId()]);
        $crawler = $board->call('buy', ['listingId' => (string) $listing->getId()])->render()->crawler();

        $this->assertStringContainsString('Carte achetée', $crawler->filter('[data-testid="market-success"]')->text());
        $this->assertCount(0, $crawler->filter('[data-testid="market-listing"]'));
        $this->assertSame(MarketPurchaseStatusEnum::COMPLETED, $this->onlyPurchase()->getStatus());
        $this->assertSame([1, 0], $this->owned($this->buyer, $card));
    }

    public function testConfirmingWithoutAskingFirstBuysNothing(): void
    {
        $listing = $this->listing($this->seller, $this->card('Never bought'), false, 25);

        $crawler = $this->board()->call('buy', ['listingId' => (string) $listing->getId()])->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="market-error"]'));
        $this->assertSame(0, $this->purchaseCount());
    }

    public function testAMalformedListingIdIsAPlainError(): void
    {
        $crawler = $this->board()->call('askBuy', ['listingId' => 'not-a-uuid'])->render()->crawler();

        $this->assertStringContainsString('plus disponible', $crawler->filter('[data-testid="market-error"]')->text());
    }

    public function testAListingSoldMeanwhileIsRefusedAtTheFirstClick(): void
    {
        $listing = $this->listing($this->seller, $this->card('Sniped'), false, 25);
        $board = $this->board();
        $listing->close(MarketListingStatusEnum::SOLD, new \DateTimeImmutable());
        $this->entityManager->flush();

        $crawler = $board->call('askBuy', ['listingId' => (string) $listing->getId()])->render()->crawler();

        $this->assertStringContainsString('plus disponible', $crawler->filter('[data-testid="market-error"]')->text());
        $this->assertCount(0, $crawler->filter('[data-testid="listing-confirm"]'));
    }

    public function testACoinRefusalIsShownAndTheListingStaysForSale(): void
    {
        $card = $this->card('Refused');
        $listing = $this->listing($this->seller, $card, false, 25);
        $this->give($this->seller, $card, 1);
        $this->coin()->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('{}', ['http_code' => 422]));
        $board = $this->board();
        $board->call('askBuy', ['listingId' => (string) $listing->getId()]);

        $crawler = $board->call('buy', ['listingId' => (string) $listing->getId()])->render()->crawler();

        $this->assertStringContainsString('Solde Youl Coin insuffisant', $crawler->filter('[data-testid="market-error"]')->text());
        $this->assertCount(1, $crawler->filter('[data-testid="market-listing"]'));
        $this->assertSame(MarketPurchaseStatusEnum::FAILED, $this->onlyPurchase()->getStatus());
    }

    public function testAnUncertainPaymentShowsThePendingNotice(): void
    {
        $listing = $this->listing($this->seller, $this->card('Uncertain'), false, 25);
        $this->coin()->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));
        $board = $this->board();
        $board->call('askBuy', ['listingId' => (string) $listing->getId()]);

        $crawler = $board->call('buy', ['listingId' => (string) $listing->getId()])->render()->crawler();

        $this->assertStringContainsString('en cours de vérification', $crawler->filter('[data-testid="market-success"]')->text());
        $this->assertCount(0, $crawler->filter('[data-testid="market-listing"]'), 'A reserved listing leaves the board.');
    }

    public function testTheBuyButtonIsDisabledWhenTheBalanceIsTooLow(): void
    {
        $this->listing($this->seller, $this->card('Expensive'), false, 25);
        $this->coin()->override('GET', '/api/user/' . self::JUJU . '/wallet', static fn (): MockResponse => CoinMockResponses::json(['id' => 'W', 'amount' => '100000000']));

        $button = $this->board()->render()->crawler()->filter('[data-testid="listing-buy"]');

        $this->assertSame('Solde insuffisant', trim($button->text()));
        $this->assertNotNull($button->attr('disabled'));
    }

    public function testTheBuyButtonIsDisabledWhenTheCoinIsUnavailable(): void
    {
        $this->listing($this->seller, $this->card('Offline'), false, 25);
        $this->coin()->override('GET', '/api/user/' . self::JUJU . '/wallet', static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        $button = $this->board()->render()->crawler()->filter('[data-testid="listing-buy"]');

        $this->assertSame('Youl Coin indisponible', trim($button->text()));
        $this->assertNotNull($button->attr('disabled'));
    }

    public function testTheBoardAndTheShopRefreshOnTheRealtimeEvent(): void
    {
        $this->assertStringContainsString('live-updates:market-changed@window->live#$render', (string) $this->board()->render()->crawler()->filter('[data-live-name-value="MarketBoard"]')->attr('data-action'));
        $this->assertStringContainsString('live-updates:market-changed@window->live#$render', (string) $this->myShop()->render()->crawler()->filter('[data-live-name-value="MyShop"]')->attr('data-action'));
    }

    public function testCreatingAWithdrawingAndSellingAreBroadcast(): void
    {
        $card = $this->card('Broadcast');
        $this->give($this->buyer, $card, 2);
        $shop = $this->myShop();
        $shop->call('startSelling', ['cardId' => (string) $card->getId(), 'finish' => 'normal']);
        $shop->set('sellPrice', '30');
        $shop->call('publish');
        $listing = $this->entityManager->getRepository(MarketListing::class)->findOneBy(['seller' => $this->buyer]);
        $shop->call('withdraw', ['listingId' => (string) $listing->getId()]);

        $events = array_values(array_filter(static::getContainer()->get(SpyHub::class)->getEvents(), static fn (array $event): bool => 'market-changed' === $event['type']));
        $this->assertSame(['active', 'withdrawn'], array_column(array_column($events, 'payload'), 'status'));
    }

    public function testAPlayerShopIsShownOnTheirProfile(): void
    {
        $card = $this->card('On the profile');
        $this->listing($this->seller, $card, false, 33);

        $crawler = $this->client->request('GET', '/joueur/' . self::BARLITO);

        self::assertResponseIsSuccessful();
        $shop = $crawler->filter('[data-testid="player-shop"]');
        $this->assertCount(1, $shop->filter('[data-testid="market-listing"]'));
        $this->assertStringContainsString($card->getName(), $shop->text());
        $this->assertCount(0, $shop->filter('[data-testid="market-filters"]'));
    }

    public function testAnEmptyPlayerShopShowsNothing(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . self::BARLITO);

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="market-listing"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="market-empty"]'));
    }

    public function testThePagesRenderAndTheHeaderLinksToTheMarket(): void
    {
        $crawler = $this->client->request('GET', '/marche');
        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('header nav a[href="/marche"]'));
        $this->assertSame('/marche/ma-boutique', $crawler->filter('[data-testid="my-shop-link"]')->attr('href'));

        $this->client->request('GET', '/marche/ma-boutique');
        self::assertResponseIsSuccessful();
    }

    public function testMyShopShowsTheQuotaAndTheListings(): void
    {
        $card = $this->card('Shop listed');
        $this->give($this->buyer, $card, 1);
        $this->listingViaService($this->buyer, $card, false, 12);

        $crawler = $this->myShop()->render()->crawler();

        $this->assertStringContainsString('1/3 annonces', $crawler->filter('[data-testid="quota"]')->text());
        $this->assertCount(1, $crawler->filter('[data-testid="my-listing"]'));
        $this->assertStringContainsString('12 YLC', $crawler->filter('[data-testid="my-listing-price"]')->text());
    }

    public function testPuttingACopyOnSaleFromTheShop(): void
    {
        $card = $this->card('Freshly listed');
        $this->give($this->buyer, $card, 2);
        $shop = $this->myShop();

        $crawler = $shop->render()->crawler();
        $this->assertCount(1, $crawler->filter('[data-testid="sellable"]'));

        $shop->call('startSelling', ['cardId' => (string) $card->getId(), 'finish' => 'normal']);
        $shop->set('sellPrice', '45');
        $crawler = $shop->call('publish')->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="shop-success"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="my-listing"]'));
        $listing = $this->entityManager->getRepository(MarketListing::class)->findOneBy(['seller' => $this->buyer]);
        $this->assertSame(45, $listing->getPrice());
        $this->assertSame(1, $this->entityManager->getRepository(MarketListing::class)->count(['seller' => $this->buyer]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPrices(): iterable
    {
        yield 'text' => ['abc'];
        yield 'zero' => ['0'];
        yield 'decimal' => ['1.5'];
        yield 'empty' => [''];
        yield 'huge' => ['99999999999'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPrices')]
    public function testAnInvalidPriceIsRefusedWithAMessage(string $price): void
    {
        $card = $this->card('Bad price');
        $this->give($this->buyer, $card, 1);
        $shop = $this->myShop();
        $shop->call('startSelling', ['cardId' => (string) $card->getId(), 'finish' => 'normal']);
        $shop->set('sellPrice', $price);

        $crawler = $shop->call('publish')->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="shop-error"]'));
        $this->assertSame(0, $this->entityManager->getRepository(MarketListing::class)->count(['seller' => $this->buyer]));
    }

    public function testTheFourthListingIsRefusedAndTheShopSaysWhy(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $card = $this->card('Quota ' . $i);
            $this->give($this->buyer, $card, 1);
            $this->listingViaService($this->buyer, $card, false, 10);
        }
        $extra = $this->card('Quota extra');
        $this->give($this->buyer, $extra, 1);

        $crawler = $this->myShop()->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="quota-reached"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="sell-copy"]'));

        $shop = $this->myShop();
        $shop->call('startSelling', ['cardId' => (string) $extra->getId(), 'finish' => 'normal']);
        $shop->set('sellPrice', '10');
        $crawler = $shop->call('publish')->render()->crawler();
        $this->assertStringContainsString('3 annonces', $crawler->filter('[data-testid="shop-error"]')->text());
    }

    public function testThePriceCanBeChangedAndTheListingWithdrawn(): void
    {
        $card = $this->card('Managed');
        $this->give($this->buyer, $card, 1);
        $listing = $this->listingViaService($this->buyer, $card, false, 10);
        $shop = $this->myShop();

        $crawler = $shop->call('startEdit', ['listingId' => (string) $listing->getId()])->render()->crawler();
        $this->assertSame('10', $crawler->filter('[data-testid="edit-price"]')->attr('value'));

        $shop->set('editPrice', '77');
        $crawler = $shop->call('savePrice', ['listingId' => (string) $listing->getId()])->render()->crawler();
        $this->assertStringContainsString('77 YLC', $crawler->filter('[data-testid="my-listing-price"]')->text());

        $crawler = $shop->call('withdraw', ['listingId' => (string) $listing->getId()])->render()->crawler();
        $this->assertCount(0, $crawler->filter('[data-testid="my-listing"]'));
        $this->assertSame(MarketListingStatusEnum::WITHDRAWN, static::getContainer()->get(EntityManagerInterface::class)->getRepository(MarketListing::class)->find($listing->getId())->getStatus());
    }

    public function testAListingWithAPurchaseInProgressCannotBeEdited(): void
    {
        $card = $this->card('Busy');
        $this->give($this->buyer, $card, 1);
        $listing = $this->listingViaService($this->buyer, $card, false, 10);
        $listing->reserveForPurchase();
        $this->entityManager->flush();

        $crawler = $this->myShop()->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="listing-busy"]'));
        $this->assertNotNull($crawler->filter('[data-testid="withdraw-listing"]')->attr('disabled'));
    }

    public function testAnotherPlayersListingCannotBeManaged(): void
    {
        $listing = $this->listing($this->seller, $this->card('Not mine'), false, 10);
        $shop = $this->myShop();

        $crawler = $shop->call('withdraw', ['listingId' => (string) $listing->getId()])->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="shop-error"]'));
        $this->assertTrue($listing->isActive());
    }

    public function testTheHistoryListsSalesAndPurchasesWithTheNetPayout(): void
    {
        $sold = $this->card('Sold by me');
        $this->give($this->buyer, $sold, 1);
        $listing = $this->listingViaService($this->buyer, $sold, false, 200);
        $bought = $this->card('Bought by me');
        $this->give($this->seller, $bought, 1);
        $theirs = $this->listingViaService($this->seller, $bought, false, 50);
        $service = static::getContainer()->get(MarketPurchaseService::class);
        $service->purchase($this->seller, $listing, 'jwt');
        $service->purchase($this->buyer, $theirs, 'jwt');

        $entries = $this->myShop()->render()->crawler()->filter('[data-testid="history-entry"]');

        $this->assertCount(2, $entries);
        $text = implode(' ', $entries->each(static fn ($entry): string => $entry->text()));
        $this->assertStringContainsString('Vendue', $text);
        $this->assertStringContainsString('reçus : 190', $text);
        $this->assertStringContainsString('Achetée', $text);
    }

    public function testACopyOnSaleShowsLockedInTheRecycleHubAndTheComposer(): void
    {
        $card = $this->card('Locked everywhere');
        $this->give($this->buyer, $card, 3);
        $this->listingViaService($this->buyer, $card, false, 10);

        $recycle = $this->createLiveComponent(RecycleHub::class, client: $this->client)->render()->crawler();
        $engaged = $recycle->filter('[data-testid="recycle-card"][data-engaged="true"]');
        $this->assertCount(1, $engaged);
        $this->assertStringContainsString('en vente', $engaged->text());

        $composer = $this->createLiveComponent(TradeComposer::class, data: ['counterpartId' => self::BARLITO], client: $this->client)->render()->crawler();
        $tile = $composer->filter('[data-testid="offered-column"] [data-testid="trade-tile"]')->reduce(static fn ($tile): bool => str_contains($tile->text(), $card->getName()));
        $this->assertCount(1, $tile);
        $this->assertStringContainsString('en vente ×1', $tile->filter('[data-testid="tile-listed"]')->text());
        $this->assertStringContainsString('2 dispo', $tile->text(), 'The two free copies stay offerable.');
    }

    public function testAFullyListedCardShowsLockedInTheComposerWithoutControls(): void
    {
        $card = $this->card('Only copy listed');
        $this->give($this->buyer, $card, 1);
        $this->listingViaService($this->buyer, $card, false, 10);

        $composer = $this->createLiveComponent(TradeComposer::class, data: ['counterpartId' => self::BARLITO], client: $this->client)->render()->crawler();
        $tile = $composer->filter('[data-testid="offered-column"] [data-testid="trade-tile"]')->reduce(static fn ($tile): bool => str_contains($tile->text(), $card->getName()));

        $this->assertCount(1, $tile);
        $this->assertStringContainsString('verrouillée', $tile->text());
        $this->assertCount(0, $tile->filter('[data-live-action-param="adjust"]'));
    }

    public function testTheSaleNotificationLinksToTheShop(): void
    {
        $card = $this->card('Notified');
        $this->give($this->buyer, $card, 1);
        $listing = $this->listingViaService($this->buyer, $card, false, 5);
        static::getContainer()->get(MarketPurchaseService::class)->purchase($this->seller, $listing, 'jwt');

        $notification = static::getContainer()->get(\App\Service\Notification\NotificationRenderer::class)->describe(\App\Enum\Notification\NotificationTypeEnum::MARKET_SOLD, ['buyerName' => 'Barlito', 'cardName' => 'X', 'price' => 5]);

        $this->assertSame('/marche/ma-boutique', $notification->link);
    }

    private function board(): TestLiveComponent
    {
        return $this->createLiveComponent(MarketBoard::class, client: $this->client);
    }

    private function myShop(): TestLiveComponent
    {
        return $this->createLiveComponent(MyShop::class, client: $this->client);
    }

    private function coin(): CoinMockResponses
    {
        return static::getContainer()->get(CoinMockResponses::class);
    }

    private function user(string $discordId): DiscordUser
    {
        $user = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);
        \assert($user instanceof DiscordUser);

        return $user;
    }

    private function card(string $name, CardRarityEnum $rarity = CardRarityEnum::COMMON, ?Extension $extension = null): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension ?? $this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity($rarity)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function give(DiscordUser $user, Card $card, int $quantity, int $holo = 0): void
    {
        $this->entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($quantity)->setHoloQuantity($holo));
        $this->entityManager->flush();
    }

    /** Direct insert: no ownership needed, the listing is only there to be displayed. */
    private function listing(DiscordUser $seller, Card $card, bool $holo, int $price): MarketListing
    {
        $listing = new MarketListing($seller, $card, $holo, $price);
        $this->entityManager->persist($listing);
        $this->entityManager->flush();

        return $listing;
    }

    private function listingViaService(DiscordUser $seller, Card $card, bool $holo, int $price): MarketListing
    {
        return static::getContainer()->get(MarketListingService::class)->create($seller, $card, $holo, $price);
    }

    private function purchaseCount(): int
    {
        return $this->entityManager->getRepository(MarketPurchase::class)->count([]);
    }

    private function onlyPurchase(): MarketPurchase
    {
        $this->entityManager->clear();
        $purchases = $this->entityManager->getRepository(MarketPurchase::class)->findAll();
        $this->assertCount(1, $purchases);

        return $purchases[0];
    }

    /**
     * @return array{int, int}
     */
    private function owned(DiscordUser $user, Card $card): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT quantity, holo_quantity FROM user_card WHERE discord_user_id = ? AND card_id = ?', [$user->getDiscordId(), (string) $card->getId()]);

        return false === $row ? [0, 0] : [(int) $row['quantity'], (int) $row['holo_quantity']];
    }
}
