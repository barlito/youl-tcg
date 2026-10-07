<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Entity\UserCard;
use App\Entity\WishlistEntry;
use App\Entity\WishlistUniverse;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Repository\DiscordUserRepository;
use App\Tests\FeatureFlagTrait;
use App\Twig\Components\MarketBoard;
use App\Twig\Components\TradeComposer;
use App\Twig\Components\WishlistPanel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class WishlistTest extends WebTestCase
{
    use FeatureFlagTrait;
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string BARLITO = '188967649332428800';

    private const string JUJU = '195659530363731968';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $me;

    private DiscordUser $other;

    private Extension $extension;

    private Card $owned;

    private Card $hidden;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.app')->clear();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->me = $this->authenticateClient($this->client, self::JUJU);
        $this->other = static::getContainer()->get(DiscordUserRepository::class)->find(self::BARLITO);

        $this->extension = new Extension()->setName('Wishlist extension ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($this->extension);
        $this->owned = $this->card('Owned card');
        $this->hidden = $this->card('Hidden card');
        $this->entityManager->persist(new UserCard()->setDiscordUser($this->me)->setCard($this->owned)->setQuantity(1));
        $this->entityManager->flush();
    }

    // ------------------------------------------------------------ routes

    public function testTheToggleEndpointWishesAndUnwishesAVisibleCard(): void
    {
        $this->client->request('POST', '/wishlist/carte/' . $this->owned->getId());
        self::assertResponseIsSuccessful();
        $this->assertSame(['active' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->assertCount(1, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $this->me]));

        $this->client->request('POST', '/wishlist/carte/' . $this->owned->getId());
        $this->assertSame(['active' => false], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->assertCount(0, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $this->me]));
    }

    public function testTheToggleEndpointRefusesACardTheViewerNeverSawAndLeaksNothing(): void
    {
        $this->client->request('POST', '/wishlist/carte/' . $this->hidden->getId());
        $hiddenStatus = $this->client->getResponse()->getStatusCode();
        $hiddenBody = (string) $this->client->getResponse()->getContent();
        $this->client->request('POST', '/wishlist/carte/00000000-0000-4000-8000-000000000000');
        $unknownStatus = $this->client->getResponse()->getStatusCode();

        $this->assertSame(404, $hiddenStatus);
        $this->assertSame($unknownStatus, $hiddenStatus, 'An unseen card answers exactly like an unknown one.');
        $this->assertStringNotContainsString($this->hidden->getName(), $hiddenBody);
        $this->assertCount(0, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $this->me]));

        $this->client->request('POST', '/wishlist/carte/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testACardOnTheMarketCanBeWishedThroughTheEndpoint(): void
    {
        $this->entityManager->persist(new MarketListing($this->other, $this->hidden, false, 10));
        $this->entityManager->flush();

        $this->client->request('POST', '/wishlist/carte/' . $this->hidden->getId());

        self::assertResponseIsSuccessful();
    }

    public function testTheEndpointRefusesTheNextWishOnceTheCapIsReached(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $card = $this->card('Capped ' . $i);
            $this->entityManager->persist(new UserCard()->setDiscordUser($this->me)->setCard($card)->setQuantity(1));
            $this->entityManager->persist(new WishlistEntry($this->me, $card));
        }
        $this->entityManager->flush();

        $this->client->request('POST', '/wishlist/carte/' . $this->owned->getId());

        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('wishlist est pleine', (string) $this->client->getResponse()->getContent());
    }

    public function testTheUniverseEndpointWatchesAndUnwatches(): void
    {
        $this->client->request('POST', '/wishlist/univers/' . $this->extension->getSlug());
        $this->assertSame(['active' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->assertCount(1, $this->entityManager->getRepository(WishlistUniverse::class)->findBy(['player' => $this->me]));

        $this->client->request('POST', '/wishlist/univers/' . $this->extension->getSlug());
        $this->assertSame(['active' => false], json_decode((string) $this->client->getResponse()->getContent(), true));

        // the request cycle cleared the identity map: reload before editing
        $this->entityManager->find(Extension::class, $this->extension->getId())->setStatus(ExtensionStatusEnum::DRAFT);
        $this->entityManager->flush();
        $this->client->request('POST', '/wishlist/univers/' . $this->extension->getSlug());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTogglesAreNotReachableWithGet(): void
    {
        $this->client->request('GET', '/wishlist/carte/' . $this->owned->getId());
        self::assertResponseStatusCodeSame(405);
    }

    // ------------------------------------------------------- feature flag

    public function testEverythingAnswersNotFoundWhileTheFlagIsOff(): void
    {
        $this->client->request('GET', '/wishlist');
        self::assertResponseIsSuccessful();

        $this->setFeature(FeatureEnum::WISHLIST, false);
        $this->client->request('GET', '/wishlist');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/wishlist/carte/' . $this->owned->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/wishlist/univers/' . $this->extension->getSlug());
        self::assertResponseStatusCodeSame(404);

        $panel = $this->createLiveComponent(WishlistPanel::class, client: $this->client);
        try {
            $panel->call('unwatch', ['slug' => $this->extension->getSlug()]);
            $this->fail('The panel actions must answer 404 while the wishlist is off.');
        } catch (NotFoundHttpException) {
        }
        $this->assertCount(0, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $this->me]));
    }

    public function testNoHeartIsRenderedWhileTheFlagIsOff(): void
    {
        $this->setFeature(FeatureEnum::WISHLIST, false);
        $this->entityManager->persist(new MarketListing($this->other, $this->hidden, false, 10));
        $this->entityManager->flush();

        $collection = $this->client->request('GET', '/collection');
        $universe = $this->client->request('GET', '/univers/' . $this->extension->getSlug());
        $market = $this->createLiveComponent(MarketBoard::class, client: $this->client)->render()->crawler();

        $this->assertCount(0, $collection->filter('[data-testid="wish-heart"], [data-testid="wishlist-link"]'));
        $this->assertCount(0, $universe->filter('[data-testid="wish-heart"], [data-testid="wish-universe"]'));
        $this->assertCount(0, $market->filter('[data-testid="listing-wish"], [data-testid="filter-wishlist"]'));
    }

    // -------------------------------------------------------------- masking

    public function testTheUniversePageOffersOnlyTheUniverseWatchForMaskedCards(): void
    {
        $crawler = $this->client->request('GET', '/univers/' . $this->extension->getSlug());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $button = $crawler->filter('[data-testid="wish-universe"]');
        $this->assertCount(1, $button);
        $this->assertSame('/wishlist/univers/' . $this->extension->getSlug(), $button->attr('data-wish-toggle-url-value'));
        $this->assertStringContainsString('Me prévenir pour mes cartes manquantes', $button->text());

        $this->assertStringNotContainsString($this->hidden->getName(), $html);
        $this->assertStringNotContainsString((string) $this->hidden->getId(), $html);
        // the only card heart is the one of the owned card
        $hearts = $crawler->filter('[data-testid="wish-heart"]');
        $this->assertCount(1, $hearts);
        $this->assertSame('/wishlist/carte/' . $this->owned->getId(), $hearts->attr('data-wish-toggle-url-value'));
    }

    public function testTheUniverseWatchShowsAsActiveOnceSubscribed(): void
    {
        $this->entityManager->persist(new WishlistUniverse($this->me, $this->extension));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/univers/' . $this->extension->getSlug());

        $button = $crawler->filter('[data-testid="wish-universe"]');
        $this->assertSame('true', $button->attr('aria-pressed'));
        $this->assertStringContainsString('Alertes actives', $button->text());
    }

    public function testTheCollectionOffersTheHeartOnOwnedCardsOnly(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extension->getSlug());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertCount(1, $crawler->filter('[data-testid="wish-heart"]'));
        $this->assertStringNotContainsString((string) $this->hidden->getId(), $html);
        $this->assertStringNotContainsString($this->hidden->getName(), $html);
    }

    public function testThePanelShowsAKnownWishInClearAndAnUnseenOneAsACardBack(): void
    {
        $this->entityManager->persist(new WishlistEntry($this->me, $this->owned));
        $this->entityManager->persist(new WishlistEntry($this->me, $this->hidden));
        $this->entityManager->flush();

        $crawler = $this->createLiveComponent(WishlistPanel::class, client: $this->client)->render()->crawler();

        $this->assertCount(2, $crawler->filter('[data-testid="wishlist-entry"]'));
        $text = $crawler->filter('[data-testid="wishlist-grid"]')->text();
        $this->assertStringContainsString($this->owned->getName(), $text);
        $this->assertStringNotContainsString($this->hidden->getName(), $text);
        $this->assertCount(1, $crawler->filter('[data-testid="wishlist-grid"] [data-testid="masked-card"]'));
        $this->assertStringNotContainsString((string) $this->hidden->getId(), $crawler->html());
        $this->assertStringContainsString('2/30', $crawler->filter('[data-testid="wishlist-count"]')->text());
    }

    public function testThePanelCountsTheMissingCardsOfAWatchedUniverseWithoutListingThem(): void
    {
        $this->entityManager->persist(new WishlistUniverse($this->me, $this->extension));
        $this->entityManager->flush();

        $panel = $this->createLiveComponent(WishlistPanel::class, client: $this->client);
        $crawler = $panel->render()->crawler();

        $this->assertStringContainsString('1 carte manquante surveillée', $crawler->filter('[data-testid="wishlist-missing"]')->text());
        $this->assertStringNotContainsString($this->hidden->getName(), $crawler->html());

        $crawler = $panel->call('unwatch', ['slug' => $this->extension->getSlug()])->render()->crawler();
        $this->assertCount(0, $crawler->filter('[data-testid="wishlist-universe"]'));
    }

    public function testThePanelRemovesAnEntryAndShowsWhatIsOnSale(): void
    {
        $entry = new WishlistEntry($this->me, $this->owned);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new MarketListing($this->other, $this->owned, false, 10));
        $this->entityManager->flush();

        $panel = $this->createLiveComponent(WishlistPanel::class, client: $this->client);
        $this->assertCount(1, $panel->render()->crawler()->filter('[data-testid="wishlist-listed"]'));

        $crawler = $panel->call('removeEntry', ['entryId' => (string) $entry->getId()])->render()->crawler();

        $this->assertCount(0, $crawler->filter('[data-testid="wishlist-entry"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="wishlist-empty"]'));
    }

    // --------------------------------------------------------------- market

    public function testTheMarketHeartTogglesTheWishAndTheFilterNarrowsTheBoard(): void
    {
        $wished = $this->card('Market wished');
        $other = $this->card('Market other');
        $wishedListing = new MarketListing($this->other, $wished, false, 10);
        $this->entityManager->persist($wishedListing);
        $this->entityManager->persist(new MarketListing($this->other, $other, false, 20));
        $this->entityManager->flush();
        $board = $this->createLiveComponent(MarketBoard::class, client: $this->client);

        $crawler = $board->call('toggleWish', ['listingId' => (string) $wishedListing->getId()])->render()->crawler();
        $this->assertCount(1, $crawler->filter('[data-testid="listing-wish"][aria-pressed="true"]'));
        $this->assertCount(2, $crawler->filter('[data-testid="market-listing"]'));

        $crawler = $board->call('toggleWishlistFilter')->render()->crawler();
        $this->assertCount(1, $crawler->filter('[data-testid="market-listing"]'));
        $this->assertStringContainsString($wished->getName(), $crawler->filter('[data-testid="market-listing"]')->text());

        $crawler = $board->call('resetFilters')->render()->crawler();
        $this->assertCount(2, $crawler->filter('[data-testid="market-listing"]'));
    }

    public function testTheMarketFilterAlsoCoversTheMissingCardsOfAWatchedUniverse(): void
    {
        $otherExtension = new Extension()->setName('Elsewhere ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($otherExtension);
        $missing = $this->card('Watched missing');
        $ownedInWatched = $this->card('Watched owned');
        $elsewhere = $this->card('Not watched', extension: $otherExtension);
        $this->entityManager->persist(new UserCard()->setDiscordUser($this->me)->setCard($ownedInWatched)->setQuantity(1));
        $this->entityManager->persist(new WishlistUniverse($this->me, $this->extension));
        foreach ([$missing, $ownedInWatched, $elsewhere] as $card) {
            $this->entityManager->persist(new MarketListing($this->other, $card, false, 10));
        }
        $this->entityManager->flush();

        $board = $this->createLiveComponent(MarketBoard::class, client: $this->client);
        $crawler = $board->call('toggleWishlistFilter')->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="market-listing"]'));
        $this->assertStringContainsString($missing->getName(), $crawler->filter('[data-testid="market-listing"]')->text());
    }

    public function testTheMarketHeartRefusesAClosedListing(): void
    {
        $listing = new MarketListing($this->other, $this->hidden, false, 10);
        $this->entityManager->persist($listing);
        $this->entityManager->flush();
        $board = $this->createLiveComponent(MarketBoard::class, client: $this->client);
        $listing->close(\App\Enum\Market\MarketListingStatusEnum::WITHDRAWN, new \DateTimeImmutable());
        $this->entityManager->flush();

        $crawler = $board->call('toggleWish', ['listingId' => (string) $listing->getId()])->render()->crawler();

        $this->assertStringContainsString('plus disponible', $crawler->filter('[data-testid="market-error"]')->text());
        $this->assertCount(0, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $this->me]));
    }

    // ------------------------------------------------------------- composer

    public function testTheComposerBadgesMyCardsHeLooksForWithoutLeakingAnythingElse(): void
    {
        $direct = $this->card('Wanted directly');
        $viaUniverse = $this->card('Wanted via universe');
        $notWanted = $this->card('Not wanted');
        foreach ([$direct, $viaUniverse, $notWanted] as $card) {
            $this->entityManager->persist(new UserCard()->setDiscordUser($this->other)->setCard($card)->setQuantity(1));
        }
        foreach ([$direct, $viaUniverse, $notWanted] as $card) {
            $this->entityManager->persist(new UserCard()->setDiscordUser($this->me)->setCard($card)->setQuantity(1));
        }
        $this->entityManager->flush();
        // he owns none of mine in the watched universe except ... nothing: make him miss two of them
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserCard uc WHERE uc.discordUser = :user AND uc.card IN (:cards)')
            ->setParameter('user', $this->other)->setParameter('cards', [$direct, $viaUniverse])->execute()
        ;
        $this->entityManager->persist(new WishlistEntry($this->other, $direct));
        $this->entityManager->persist(new WishlistUniverse($this->other, $this->extension));
        $this->entityManager->flush();

        $html = (string) $this->composer(self::BARLITO)->render();

        $badged = array_map('trim', new Crawler($html)->filter('[data-testid="offered-column"] [data-testid="trade-tile"]')->reduce(
            static fn (Crawler $tile): bool => $tile->filter('[data-testid="hint-wanted"]')->count() > 0,
        )->each(static fn (Crawler $tile): string => $tile->filter('p.truncate.font-display')->text()));
        sort($badged);
        $expected = [$direct->getName(), $viaUniverse->getName(), $this->owned->getName()];
        sort($expected);
        $this->assertSame($expected, $badged);
        $this->assertStringContainsString('♡ Barlito la cherche', $html);
        // the right column never carries the badge
        $this->assertCount(0, new Crawler($html)->filter('[data-testid="requested-column"] [data-testid="hint-wanted"]'));
    }

    public function testTheComposerChipFiltersWhatHeLooksFor(): void
    {
        $wanted = $this->card('Wanted by him');
        $this->entityManager->persist(new UserCard()->setDiscordUser($this->me)->setCard($wanted)->setQuantity(1));
        $this->entityManager->persist(new WishlistEntry($this->other, $wanted));
        $this->entityManager->flush();
        $composer = $this->composer(self::BARLITO);

        $html = (string) $composer->render();
        $this->assertStringContainsString('Il la cherche', new Crawler($html)->filter('[data-testid="chip-offered-wanted"]')->text());
        $this->assertGreaterThan(1, new Crawler($html)->filter('[data-testid="offered-column"] [data-testid="trade-tile"]')->count());

        $html = (string) $composer->call('filterSide', ['side' => 'offered', 'filter' => 'wanted'])->render();
        $tiles = new Crawler($html)->filter('[data-testid="offered-column"] [data-testid="trade-tile"]');
        $this->assertCount(1, $tiles);
        $this->assertStringContainsString($wanted->getName(), $tiles->text());
    }

    public function testTheComposerBadgeAndChipDisappearWhileTheFlagIsOff(): void
    {
        $wanted = $this->card('Wanted by him');
        $this->entityManager->persist(new UserCard()->setDiscordUser($this->me)->setCard($wanted)->setQuantity(1));
        $this->entityManager->persist(new WishlistEntry($this->other, $wanted));
        $this->entityManager->flush();
        $this->setFeature(FeatureEnum::WISHLIST, false);

        $html = (string) $this->composer(self::BARLITO)->render();

        $this->assertStringNotContainsString('la cherche', $html);
        $this->assertCount(0, new Crawler($html)->filter('[data-testid="chip-offered-wanted"], [data-testid="tile-wish"]'));
    }

    public function testTheComposerHeartWorksOnClearCardsAndIgnoresMaskedTokens(): void
    {
        $theirs = $this->card('Only his');
        $this->entityManager->persist(new UserCard()->setDiscordUser($this->other)->setCard($theirs)->setQuantity(1));
        $this->entityManager->flush();
        $composer = $this->composer(self::BARLITO);
        $html = (string) $composer->render();

        $maskedTile = new Crawler($html)->filter('[data-testid="requested-column"] [data-testid="trade-tile"]')->reduce(
            static fn (Crawler $tile): bool => $tile->filter('[data-testid="masked-card"]')->count() > 0,
        )->first();
        $this->assertCount(0, $maskedTile->filter('[data-testid="tile-wish"]'), 'A masked tile has no heart.');
        $maskedToken = (string) $maskedTile->filter('[data-live-token-param]')->attr('data-live-token-param');
        $composer->call('toggleWish', ['token' => $maskedToken]);
        $this->assertCount(0, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $this->me]));

        $ownedTile = new Crawler($html)->filter('[data-testid="offered-column"] [data-testid="trade-tile"]')->reduce(
            fn (Crawler $tile): bool => str_contains($tile->text(), $this->owned->getName()),
        )->first();
        $token = (string) $ownedTile->filter('[data-testid="tile-wish"]')->attr('data-live-token-param');
        $html = (string) $composer->call('toggleWish', ['token' => $token])->render();

        $this->assertCount(1, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $this->me]));
        $this->assertCount(1, new Crawler($html)->filter('[data-testid="offered-column"] [data-testid="tile-wish"][aria-pressed="true"]'));
        $this->assertStringNotContainsString((string) $this->owned->getId(), $html);
    }

    private function composer(string $counterpartId): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        return $this->createLiveComponent(TradeComposer::class, data: ['counterpartId' => $counterpartId], client: $this->client);
    }

    private function card(string $name, ?Extension $extension = null): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension ?? $this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }
}
