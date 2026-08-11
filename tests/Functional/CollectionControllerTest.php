<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * « Ma collection »: the merged page — profile stats, completion strip and the
 * whole published catalogue split by universe, the cards not owned yet masked.
 */
final class CollectionControllerTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string USER_WITHOUT_FIXTURE_CARDS = '195659530363731968';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    private Extension $extensionA;

    private Extension $extensionB;

    /** @var list<Card> */
    private array $cardsA = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // a player WITHOUT any fixture card: the banner totals below are exact,
        // they must not drift when the demo collections grow
        $this->user = $this->authenticateClient($this->client, self::USER_WITHOUT_FIXTURE_CARDS);

        $this->createScenario();
    }

    public function testBannerShowsUserQuotaAndProfileStats(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString($this->user->getUsername(), $crawler->filter('h1')->text());

        $quota = $crawler->filter('[data-testid="daily-packs"]')->text();
        $this->assertStringContainsString('2', $quota);
        $this->assertStringContainsString('/ 2', $quota);

        // the profile stats moved in with the merge: 3 (1 holo copy included) + 1 + 0
        $stats = $crawler->filter('[data-testid="profile-stats"]')->text();
        $this->assertStringContainsString('4 cartes au total', $stats);
        $this->assertStringContainsString('1 holo', $stats);
        $this->assertStringContainsString('0 unique 1/1', $stats);
        $this->assertStringContainsString('0 pack ouvert', $stats);
    }

    public function testBannerLinksToTheHistoryAndTheLeaderboard(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $this->assertSame('/mes-ouvertures', $crawler->filter('[data-testid="history-link"]')->attr('href'));

        $rank = $crawler->filter('[data-testid="leaderboard-link"]');
        $this->assertSame('/classement', $rank->attr('href'));
        $this->assertMatchesRegularExpression('/#\d+ au classement/', $rank->text());
    }

    public function testCompletionStripShowsPerUniverseNumbers(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $strip = $crawler->filter('[data-testid="completion-strip"]')->text();
        // Extension A: 2 owned out of 4 published (the draft card must not count).
        $this->assertStringContainsString('50%', $strip);
        $this->assertStringContainsString('2/4 cartes', $strip);
        // Extension B: nothing owned.
        $this->assertStringContainsString('0/2 cartes', $strip);
    }

    public function testCompletionStripLeadsWithTheAllCardsTile(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        // First tile is « Tout » (global completion), active by default since no
        // extension filter is applied. The base fixtures add published cards on
        // top of the scenario's, so only the owned count (2) is asserted exactly.
        $allTile = $crawler->filter('[data-testid="completion-strip"] > a')->first();
        $this->assertStringContainsString('Tout', $allTile->text());
        $this->assertMatchesRegularExpression('#\b\d+%.*\b2/\d+ cartes#s', $allTile->text());
        $this->assertNotNull($allTile->attr('data-carousel-active'));
    }

    public function testGridShowsOwnedCardsWithQuantityBadges(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $grid = $this->universeBlock($crawler, $this->extensionA)->filter('[data-testid="collection-grid"]');
        $this->assertStringContainsString('×3', $grid->text());
        $this->assertStringContainsString('✦1', $grid->text());
    }

    public function testCardsNotOwnedAreMaskedWithoutLeakingTheirNames(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $block = $this->universeBlock($crawler, $this->extensionA);

        // 4 published cards, 2 owned: the zero-quantity row counts as missing
        $this->assertCount(2, $block->filter('[data-testid="collection-tile"][data-state="common"]'));
        $this->assertCount(2, $block->filter('[data-testid="collection-tile"][data-state="missing-both"]'));
        $this->assertCount(2, $block->filter('[data-testid="masked-card"]'));

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString($this->cardsA[2]->getName(), $html);
        $this->assertStringNotContainsString($this->cardsA[3]->getName(), $html);
    }

    public function testFilterBarCountsOwnedAndMissingCards(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();
        $filters = $crawler->filter('[data-testid="collection-filters"] button');
        $states = $filters->each(static fn (Crawler $node): string => (string) $node->attr('data-state'));
        $this->assertSame(['all', 'common', 'missing-both'], $states);

        // scoped to the filtered universe, not to the whole catalogue
        $this->assertStringContainsString('4', $filters->eq(0)->text());
        $this->assertStringContainsString('2', $filters->eq(1)->text());
        $this->assertStringContainsString('2', $filters->eq(2)->text());

        // the client-side fallback exists (hidden until a filter empties the grid)
        $fallback = $crawler->filter('[data-testid="filter-empty"]');
        $this->assertCount(1, $fallback);
        $this->assertNotNull($fallback->attr('hidden'));
    }

    public function testHoloOwnedTileRendersToggleAndHoloCard(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('[data-testid="collection-grid"]');

        // Only cardsA[0] is owned in holo → exactly one toggle, pressed by default.
        $toggle = $grid->filter('button[data-testid="holo-toggle"]');
        $this->assertCount(1, $toggle);
        $this->assertSame('button', $toggle->attr('type'));
        $this->assertSame('true', $toggle->attr('aria-pressed'));

        // The tile carries the Stimulus wiring + the preset class the toggle strips.
        $tile = $grid->filter('[data-controller="holo-toggle"]');
        $this->assertCount(1, $tile);
        $this->assertSame('holo--basic', $tile->attr('data-holo-toggle-holo-class-value'));

        // The card itself is rendered holo by default (semantic marker + preset recipe).
        $holoCard = $tile->filter('.card.holo');
        $this->assertCount(1, $holoCard);
        $this->assertStringContainsString('holo--basic', (string) $holoCard->attr('class'));
        $this->assertCount(1, $holoCard->filter(\sprintf('img[alt="%s"]', $this->cardsA[0]->getName())));
    }

    public function testOnlyHoloCardRendersHoloWithoutToggle(): void
    {
        // quantity === holoQuantity → no normal copy owned: the card shows its
        // holo version but there is nothing to toggle to.
        $card = $this->createCard($this->extensionA, 'Only holo ' . uniqid());
        $this->createUserCard($card, quantity: 2, holoQuantity: 2);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();
        $tile = $this->tileOf($crawler, $card);
        $this->assertCount(0, $tile->filter('button[data-testid="holo-toggle"]'));
        $this->assertStringContainsString('holo--', (string) $tile->filter('.card')->attr('class'));
    }

    public function testCardWithoutHoloCopyHasNoToggleAndRendersNormal(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();

        // cardsA[1] is owned without any holo copy: normal rendering, no toggle on its tile.
        $tile = $this->tileOf($crawler, $this->cardsA[1]);
        $this->assertCount(0, $tile->filter('button[data-testid="holo-toggle"]'));
        $this->assertNull($tile->attr('data-controller'));
        $this->assertStringNotContainsString('holo', (string) $tile->filter('.card')->attr('class'));
    }

    public function testExtensionFilterOnlyShowsItsUniverse(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();
        // a single universe block, the filtered one — the rest of the catalogue is gone
        $blocks = $crawler->filter('[data-testid="collection-universe"]');
        $this->assertCount(1, $blocks);
        $this->assertStringContainsString($this->extensionA->getName(), $blocks->filter('h2')->text());
        $this->assertCount(1, $blocks->filter(\sprintf('img[alt="%s"]', $this->cardsA[0]->getName())));

        // The filtered universe's tile is the active one in the completion strip.
        $this->assertStringContainsString(
            $this->extensionA->getName(),
            $crawler->filter('[data-testid="completion-strip"] a[data-carousel-active]')->text(),
        );
    }

    public function testExtensionWithNothingOwnedStillShowsItsMaskedSet(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionB->getSlug());

        self::assertResponseIsSuccessful();
        // the empty state nudges to open a pack, the catalogue stays behind it
        $this->assertStringContainsString('Vide.', $crawler->filter('[data-testid="empty-state"]')->text());
        $this->assertCount(2, $crawler->filter('[data-testid="collection-tile"][data-state="missing-both"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="collection-tile"][data-state="common"]'));

        // « Possédées » has nothing to show: readable but not clickable
        $owned = $crawler->filter('[data-testid="collection-filters"] button[data-state="common"]');
        $this->assertNotNull($owned->attr('disabled'));
    }

    public function testUniverseHeadingLinksToTheUniversePage(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();
        $this->assertSame(
            '/univers/' . $this->extensionA->getSlug(),
            $crawler->filter('[data-testid="collection-universe"] h2 a')->attr('href'),
        );
    }

    public function testUnknownExtensionIsNotFound(): void
    {
        // a well-formed but non-existent slug → controller 404
        $this->client->request('GET', '/collection/this-extension-does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMalformedSlugIsNotFound(): void
    {
        // chars outside the [a-z0-9-] route requirement → no route matches → 404
        $this->client->request('GET', '/collection/Invalid_Slug');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLegacyExtensionQueryParamRedirectsToTheSlugRoute(): void
    {
        $this->client->request('GET', '/collection?extension=' . $this->extensionA->getId());

        self::assertResponseRedirects('/collection/' . $this->extensionA->getSlug(), 301);
    }

    public function testLegacyExtensionQueryParamWithUnknownIdIsNotFound(): void
    {
        // the query-param filter 404ed on unknown values — the redirect keeps that contract
        $this->client->request('GET', '/collection?extension=00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnpublishedContentNeverReachesTheGrid(): void
    {
        $draftExtension = $this->createExtension('Univers brouillon ' . uniqid(), ExtensionStatusEnum::DRAFT);
        $hidden = $this->createCard($draftExtension, 'Carte cachée ' . uniqid());
        $this->createUserCard($hidden, quantity: 1);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        // the draft card of the scenario is not part of extension A's published set
        $this->assertStringContainsString('4 cartes', $this->universeBlock($crawler, $this->extensionA)->filter('[data-testid="universe-count"]')->text());
        $this->assertStringNotContainsString($draftExtension->getName(), $html);
        $this->assertStringNotContainsString($hidden->getName(), $html);
    }

    public function testGridOrdersCardsRarestFirst(): void
    {
        // a legendary and a rare on top of the owned commons — the grid must lead with them
        $legendary = $this->createCard($this->extensionA, 'Rarity test legendary ' . uniqid(), rarity: CardRarityEnum::LEGENDARY);
        $rare = $this->createCard($this->extensionA, 'Rarity test rare ' . uniqid(), rarity: CardRarityEnum::RARE);
        $this->createUserCard($legendary, quantity: 1);
        $this->createUserCard($rare, quantity: 1);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();
        // masked tiles carry no artwork: only the revealed cards are named here
        $names = $crawler->filter('[data-testid="collection-grid"] img[alt]')->extract(['alt']);
        $names = array_values(array_filter($names, static fn (string $name): bool => '' !== $name));

        $this->assertSame($legendary->getName(), $names[0] ?? null, 'Rarest card must come first.');
        $this->assertSame($rare->getName(), $names[1] ?? null, 'Then the rare, before the commons.');
    }

    /**
     * The universe block of an extension: /collection renders the whole
     * catalogue, so every grid assertion is scoped to the scenario.
     */
    private function universeBlock(Crawler $crawler, Extension $extension): Crawler
    {
        $block = $crawler->filter('[data-testid="collection-universe"]')->reduce(
            static fn (Crawler $node): bool => str_contains($node->filter('h2')->text(), $extension->getName()),
        );

        $this->assertCount(1, $block, 'The scenario universe must appear exactly once in the grid.');

        return $block;
    }

    private function tileOf(Crawler $crawler, Card $card): Crawler
    {
        $tile = $crawler->filter('[data-testid="collection-tile"]')->reduce(
            static fn (Crawler $node): bool => $node->filter(\sprintf('img[alt="%s"]', $card->getName()))->count() > 0,
        );

        $this->assertCount(1, $tile, \sprintf('Card "%s" must appear exactly once in the grid.', $card->getName()));

        return $tile;
    }

    /**
     * Extension A: 4 published cards (+1 draft) — the user owns 2 of them
     * (one ×3 with 1 holo, one ×1) plus a zero-quantity leftover row.
     * Extension B: 2 published cards — the user owns none.
     */
    private function createScenario(): void
    {
        $this->extensionA = $this->createExtension('Collection ext A ' . uniqid());
        $this->extensionB = $this->createExtension('Collection ext B ' . uniqid());

        for ($i = 0; $i < 4; ++$i) {
            $this->cardsA[] = $this->createCard($this->extensionA, \sprintf('Card A%d %s', $i, uniqid()));
        }
        $this->createCard($this->extensionA, 'Draft card ' . uniqid(), CardStatusEnum::DRAFT);

        for ($i = 0; $i < 2; ++$i) {
            $this->createCard($this->extensionB, \sprintf('Card B%d %s', $i, uniqid()));
        }

        $this->createUserCard($this->cardsA[0], quantity: 3, holoQuantity: 1);
        $this->createUserCard($this->cardsA[1], quantity: 1);
        $this->createUserCard($this->cardsA[2], quantity: 0);

        $this->entityManager->flush();
    }

    private function createExtension(string $name, ExtensionStatusEnum $status = ExtensionStatusEnum::PUBLISHED): Extension
    {
        $extension = new Extension()
            ->setName($name)
            ->setDescription('Test extension')
            ->setStatus($status)
        ;
        $this->entityManager->persist($extension);

        return $extension;
    }

    private function createCard(Extension $extension, string $name, CardStatusEnum $status = CardStatusEnum::PUBLISHED, CardRarityEnum $rarity = CardRarityEnum::COMMON): Card
    {
        $card = new Card()
            ->setName($name)
            ->setDescription('Test card')
            ->setStatus($status)
            ->setRarity($rarity)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        return $card;
    }

    private function createUserCard(Card $card, int $quantity, int $holoQuantity = 0): void
    {
        $userCard = new UserCard()
            ->setDiscordUser($this->user)
            ->setCard($card)
            ->setQuantity($quantity)
            ->setHoloQuantity($holoQuantity)
        ;
        $this->entityManager->persist($userCard);
    }
}
