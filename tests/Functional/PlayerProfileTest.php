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
 * The compared profile grid: the whole published catalogue, crossed with the
 * visitor's collection. A card of the profile is only revealed when the visitor
 * owns it too; a card the profile does NOT own may be revealed (nothing to
 * hide); a 1/1 the visitor doesn't hold stays a mystery, out of the counters.
 */
final class PlayerProfileTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    private DiscordUser $rival;

    private Extension $extension;

    private Card $sharedCard;

    private Card $secretCard;

    private Card $visitorCard;

    private Card $missingCard;

    private Card $uniqueCard;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);

        $this->createScenario();
    }

    public function testProfileHeaderShowsIdentityRankAndStats(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString($this->rival->getUsername(), $crawler->filter('h1')->text());
        $this->assertStringContainsString('joueur depuis', $crawler->filter('main')->text());
        $this->assertStringContainsString('3 cartes distinctes', $crawler->filter('[data-testid="profile-completion"]')->text());

        $stats = $crawler->filter('[data-testid="profile-stats"]')->text();
        $this->assertStringContainsString('4 cartes au total', $stats);
        $this->assertStringContainsString('1 holo', $stats);
        $this->assertStringContainsString('1 unique 1/1', $stats);
        $this->assertStringContainsString('#', $crawler->filter('[data-testid="profile-rank"]')->text());
    }

    public function testSharedCardIsShownInClear(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(\sprintf('img[alt="%s"]', $this->sharedCard->getName())));
        // the profile's own quantities are public: ×2 and one holo chip
        $this->assertStringContainsString('×2', $this->universeBlock($crawler)->filter('[data-testid="profile-grid"]')->text());
        $this->assertStringContainsString('✦1', $this->universeBlock($crawler)->filter('[data-testid="profile-grid"]')->text());
    }

    public function testUnsharedCardsAreMaskedWithoutLeakingTheirNames(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        // secret (their card) + unique (mystery) + missing (nobody's): three card backs
        $this->assertCount(3, $this->universeBlock($crawler)->filter('[data-testid="masked-card"]'));

        // no leak at all in the HTML — covers text, alt, title and aria attributes
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString($this->secretCard->getName(), $html);
        $this->assertStringNotContainsString($this->uniqueCard->getName(), $html);
        $this->assertStringNotContainsString($this->missingCard->getName(), $html);
    }

    public function testCardMissingFromTheProfileIsShownWhenTheVisitorOwnsIt(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        // nothing of the profile to hide here: the visitor already knows the card
        $this->assertCount(1, $crawler->filter(\sprintf('img[alt="%s"]', $this->visitorCard->getName())));

        $tile = $this->universeBlock($crawler)->filter('[data-testid="profile-tile"][data-state="visitor-only"]');
        $this->assertCount(1, $tile);
        $this->assertStringContainsString('Manque', $tile->text());
    }

    public function testEveryComparisonStateIsExposedOnTheTiles(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $block = $this->universeBlock($crawler);
        foreach (['common', 'profile-only', 'visitor-only', 'missing-both', 'mystery'] as $state) {
            $this->assertCount(
                1,
                $block->filter(\sprintf('[data-testid="profile-tile"][data-state="%s"]', $state)),
                \sprintf('Exactly one tile is expected in state "%s".', $state),
            );
        }
    }

    public function testMaskedTilesTellOwnershipApartWithoutNamingTheCard(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $block = $this->universeBlock($crawler);

        // "they own it" is not a new disclosure: before the comparison grid, the
        // profile listed their cards only, so every card back already meant that
        $owned = $block->filter('[data-testid="profile-tile"][data-state="profile-only"]');
        $this->assertCount(1, $owned->filter('[data-testid="chip-owned"]'));
        $this->assertCount(1, $owned->filter('[data-testid="masked-card"]'));
        $this->assertStringNotContainsString($this->secretCard->getName(), (string) $owned->html());

        // the 1/1 wears the same badge whether it is held or not: no holder leak
        $mystery = $block->filter('[data-testid="profile-tile"][data-state="mystery"]');
        $this->assertCount(1, $mystery->filter('[data-testid="chip-mystery"]'));
        $this->assertCount(0, $mystery->filter('[data-testid="chip-owned"]'));

        // nobody owns it: no ownership chip at all
        $orphan = $block->filter('[data-testid="profile-tile"][data-state="missing-both"]');
        $this->assertCount(0, $orphan->filter('[data-testid="chip-owned"]'));
        $this->assertCount(0, $orphan->filter('[data-testid="chip-mystery"]'));
    }

    public function testUniverseCountersCompareBothCollections(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $block = $this->universeBlock($crawler);
        $this->assertStringContainsString('5 cartes', $block->filter('[data-testid="universe-count"]')->text());

        $counters = $block->filter('[data-testid="universe-compare"]')->text();
        $this->assertStringContainsString('1 en commun', $counters);
        $this->assertStringContainsString('1 seulement ' . $this->rival->getUsername(), $counters);
        $this->assertStringContainsString('1 seulement toi', $counters);
        $this->assertStringContainsString('1 manquante aux deux', $counters);
        // the 1/1 is counted apart, so a single unique can't be solved by subtraction
        $this->assertStringContainsString('1 × 1/1 hors décompte', $counters);
    }

    public function testFilterBarOffersEveryBucket(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $states = $crawler->filter('[data-testid="profile-filters"] button')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-state'),
        );

        $this->assertSame(['all', 'common', 'profile-only', 'visitor-only', 'missing-both'], $states);
    }

    public function testOwnProfileShowsEverythingOwnedInClear(): void
    {
        // the rival visits their own profile: their cards are all in clear, the
        // 1/1 included, and only what they miss stays face down
        $this->authenticateClient($this->client, $this->rival->getDiscordId());
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(\sprintf('img[alt="%s"]', $this->secretCard->getName())));
        $this->assertCount(1, $crawler->filter(\sprintf('img[alt="%s"]', $this->uniqueCard->getName())));

        // the two cards they don't own (the visitor's one and the orphan one)
        $block = $this->universeBlock($crawler);
        $this->assertCount(2, $block->filter('[data-testid="masked-card"]'));
        $this->assertCount(2, $block->filter('[data-testid="profile-tile"][data-state="missing-both"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="masking-hint"]'));

        $counters = $block->filter('[data-testid="universe-compare"]')->text();
        $this->assertStringContainsString('3 possédées', $counters);
        $this->assertStringContainsString('2 manquantes', $counters);
        $this->assertStringNotContainsString('seulement', $counters);
    }

    public function testAnotherPlayersUniqueIsAlwaysMaskedForVisitors(): void
    {
        // nobody but the claimer can own a 1/1, so it can never be shown to a visitor
        $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            $this->uniqueCard->getName(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testEmptyProfileStillShowsTheCatalogueBehindAnEmptyState(): void
    {
        $empty = new DiscordUser()
            ->setDiscordId((string) random_int(300000000000000000, 999999999999999999))
            ->setUsername('Empty ' . uniqid())
        ;
        $this->entityManager->persist($empty);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/joueur/' . $empty->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="empty-state"]'));
        // the comparison still stands: the two cards the visitor owns are shown
        $this->assertCount(2, $this->universeBlock($crawler)->filter('[data-testid="profile-tile"][data-state="visitor-only"]'));
    }

    public function testUnpublishedContentNeverReachesTheGrid(): void
    {
        $draftCard = $this->createCard('Brouillon ' . uniqid());
        $draftCard->setStatus(CardStatusEnum::DRAFT);

        $draftExtension = new Extension()
            ->setName('Univers brouillon ' . uniqid())
            ->setDescription('Univers non publié')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($draftExtension);

        $hiddenCard = new Card()
            ->setName('Carte cachée ' . uniqid())
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($draftExtension)
        ;
        $hiddenCard->setImageName('default_card.png');
        $this->entityManager->persist($hiddenCard);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        // the draft card is not part of the published set of its (published) universe
        $this->assertStringContainsString('5 cartes', $this->universeBlock($crawler)->filter('[data-testid="universe-count"]')->text());

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString($draftCard->getName(), $html);
        $this->assertStringNotContainsString($draftExtension->getName(), $html);
    }

    public function testUnknownPlayerIsNotFound(): void
    {
        $this->client->request('GET', '/joueur/111111111111111111');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonNumericIdentifierIsNotFound(): void
    {
        $this->client->request('GET', '/joueur/not-a-discord-id');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The universe block of the scenario: the test database also holds the dev
     * catalogue, so every grid assertion is scoped to it.
     */
    private function universeBlock(Crawler $crawler): Crawler
    {
        $block = $crawler->filter('[data-testid="profile-universe"]')->reduce(
            fn (Crawler $node): bool => str_contains($node->filter('h2')->text(), $this->extension->getName()),
        );

        $this->assertCount(1, $block, 'The scenario universe must appear exactly once in the grid.');

        return $block;
    }

    /**
     * A published universe of 5 cards covering every comparison state: shared
     * (both), secret (rival only), visitor (visitor only), missing (nobody) and
     * a 1/1 claimed by the rival.
     */
    private function createScenario(): void
    {
        $this->extension = new Extension()
            ->setName('Univers profil ' . uniqid())
            ->setDescription('Univers du profil')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);

        $this->rival = new DiscordUser()
            ->setDiscordId((string) random_int(300000000000000000, 999999999999999999))
            ->setUsername('Rival ' . uniqid())
        ;
        $this->entityManager->persist($this->rival);

        $this->sharedCard = $this->createCard('Carte Partagée ' . uniqid());
        $this->secretCard = $this->createCard('Carte Secrète ' . uniqid());
        $this->visitorCard = $this->createCard('Carte Visiteur ' . uniqid());
        $this->missingCard = $this->createCard('Carte Orpheline ' . uniqid());
        $this->uniqueCard = $this->createCard('Unique Mystère ' . uniqid(), CardRarityEnum::LEGENDARY);
        $this->uniqueCard->setUnique(true);
        $this->uniqueCard->setClaimedBy($this->rival);

        $this->giveCard($this->rival, $this->sharedCard, quantity: 2, holoQuantity: 1);
        $this->giveCard($this->rival, $this->secretCard);
        $this->giveCard($this->rival, $this->uniqueCard);
        $this->giveCard($this->user, $this->sharedCard);
        $this->giveCard($this->user, $this->visitorCard);

        $this->entityManager->flush();
    }

    private function createCard(string $name, CardRarityEnum $rarity = CardRarityEnum::COMMON): Card
    {
        $card = new Card()
            ->setName($name)
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity($rarity)
            ->setExtension($this->extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        return $card;
    }

    private function giveCard(DiscordUser $owner, Card $card, int $quantity = 1, int $holoQuantity = 0): void
    {
        $this->entityManager->persist(
            new UserCard()
                ->setDiscordUser($owner)
                ->setCard($card)
                ->setQuantity($quantity)
                ->setHoloQuantity($holoQuantity),
        );
    }
}
