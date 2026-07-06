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

final class CollectionControllerTest extends WebTestCase
{
    use JwtAuthTrait;

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
        $this->user = $this->authenticateClient($this->client);

        $this->createScenario();
    }

    public function testBannerShowsUserAndRealQuota(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString($this->user->getUsername(), $crawler->filter('h1')->text());

        $quota = $crawler->filter('[data-testid="daily-packs"]')->text();
        $this->assertStringContainsString('2', $quota);
        $this->assertStringContainsString('/ 2', $quota);

        $this->assertStringContainsString(
            'Cartes possédées · 5',
            $crawler->filter('main')->text(),
        );
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

    public function testFilterTabsShowOwnedCounts(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $filters = $crawler->filter('[data-testid="filters"]')->text();
        $this->assertStringContainsString(\sprintf('%s · 2', $this->extensionA->getName()), $filters);
        $this->assertStringContainsString(\sprintf('%s · 0', $this->extensionB->getName()), $filters);
    }

    public function testGridShowsOwnedCardsWithQuantityBadges(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('[data-testid="collection-grid"]');
        $this->assertStringContainsString('×3', $grid->text());
        $this->assertStringContainsString('✦1', $grid->text());
        // The zero-quantity inventory row must not produce a card.
        $this->assertCount(0, $grid->filter(\sprintf('img[alt="%s"]', $this->cardsA[2]->getName())));
    }

    public function testHoloOwnedTileRendersToggleAndHoloCard(): void
    {
        $crawler = $this->client->request('GET', '/collection');

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

    public function testCardWithoutHoloCopyHasNoToggleAndRendersNormal(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();

        // cardsA[1] is owned without any holo copy: normal rendering, no toggle on its tile.
        $tile = $crawler->filter('[data-testid="collection-grid"] > div')->reduce(
            fn (Crawler $node): bool => $node->filter(\sprintf('img[alt="%s"]', $this->cardsA[1]->getName()))->count() > 0,
        );
        $this->assertCount(1, $tile);
        $this->assertCount(0, $tile->filter('button[data-testid="holo-toggle"]'));
        $this->assertNull($tile->attr('data-controller'));
        $this->assertStringNotContainsString('holo', (string) $tile->filter('.card')->attr('class'));
    }

    public function testExtensionFilterOnlyShowsItsCards(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('[data-testid="collection-grid"]');
        $this->assertCount(1, $grid->filter(\sprintf('img[alt="%s"]', $this->cardsA[0]->getName())));
        $this->assertCount(1, $grid->filter(\sprintf('img[alt="%s"]', $this->cardsA[1]->getName())));

        // Active tab is highlighted.
        $this->assertStringContainsString(
            $this->extensionA->getName(),
            $crawler->filter('[data-testid="filters"] a.bg-primary')->text(),
        );
    }

    public function testEmptyStateWhenNoCardOwnedInExtension(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionB->getSlug());

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="collection-grid"]'));
        $this->assertStringContainsString('Vide.', $crawler->filter('[data-testid="empty-state"]')->text());
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

    public function testGridOrdersOwnedCardsRarestFirst(): void
    {
        // a legendary and a rare on top of the owned commons — the grid must lead with them
        $legendary = $this->createCard($this->extensionA, 'Rarity test legendary ' . uniqid(), rarity: CardRarityEnum::LEGENDARY);
        $rare = $this->createCard($this->extensionA, 'Rarity test rare ' . uniqid(), rarity: CardRarityEnum::RARE);
        $this->createUserCard($legendary, quantity: 1);
        $this->createUserCard($rare, quantity: 1);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $names = $crawler->filter('[data-testid="collection-grid"] img[alt]')->extract(['alt']);
        $names = array_values(array_filter($names, static fn (string $name): bool => '' !== $name));

        $this->assertSame($legendary->getName(), $names[0] ?? null, 'Rarest card must come first.');
        $this->assertSame($rare->getName(), $names[1] ?? null, 'Then the rare, before the commons.');
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

    private function createExtension(string $name): Extension
    {
        $extension = new Extension()
            ->setName($name)
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
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
