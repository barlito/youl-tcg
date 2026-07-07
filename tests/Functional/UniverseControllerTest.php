<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UniverseControllerTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    private Extension $extension;

    /** @var list<Card> */
    private array $cards = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);

        $this->createScenario();
    }

    public function testIndexListsPublishedUniversesWithCompletion(): void
    {
        $crawler = $this->client->request('GET', '/univers');

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('[data-testid="universes-grid"]');
        $this->assertStringContainsString($this->extension->getName(), $grid->text());
        // 1 owned of 3 published cards (the draft one must not count) = 33%
        $this->assertStringContainsString('1/3 cartes', $grid->text());
        $this->assertStringContainsString('33%', $grid->text());
        // the tile links to the universe page
        $this->assertGreaterThan(
            0,
            $grid->filter(\sprintf('a[href="/univers/%s"]', $this->extension->getSlug()))->count(),
        );
    }

    public function testShowRendersHeroWithFullDescriptionAndStats(): void
    {
        $crawler = $this->client->request('GET', '/univers/' . $this->extension->getSlug());

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString($this->extension->getName(), $crawler->filter('h1')->text());
        // full description, not the 90-char homepage teaser
        $this->assertStringContainsString($this->extension->getDescription(), $crawler->filter('main')->text());

        $stats = $crawler->filter('[data-testid="universe-stats"]')->text();
        $this->assertStringContainsString('1/3', $stats);
        $this->assertStringContainsString('33%', $stats);
    }

    public function testSetGridShowsOwnedCardsAndMasksTheRest(): void
    {
        $crawler = $this->client->request('GET', '/univers/' . $this->extension->getSlug());

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('[data-testid="set-grid"]');

        // owned card: artwork + quantity chip
        $this->assertCount(1, $grid->filter(\sprintf('img[alt="%s"]', $this->cards[0]->getName())));
        $this->assertStringContainsString('×2', $grid->text());

        // unowned cards: masked tiles, no artwork, no name leak
        $this->assertCount(2, $grid->filter('[data-testid="masked-card"]'));
        $this->assertStringNotContainsString($this->cards[1]->getName(), $grid->text());
    }

    public function testUniqueStatusSwitchesFromPoolToDropped(): void
    {
        $unique = $this->createCard('Unique ' . uniqid(), CardRarityEnum::LEGENDARY);
        $unique->setUnique(true);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/univers/' . $this->extension->getSlug());
        $this->assertStringContainsString('encore dans le pool', $crawler->filter('[data-testid="uniques-status"]')->text());

        $unique->setClaimedBy($this->user);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/univers/' . $this->extension->getSlug());
        $this->assertStringContainsString('droppée', $crawler->filter('[data-testid="uniques-status"]')->text());
    }

    public function testNonClaimableBoosterIsHiddenUntilOwned(): void
    {
        $eventBooster = $this->createBooster('Pack Event Univers', claimable: false);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/univers/' . $this->extension->getSlug());
        $boosters = $crawler->filter('[data-testid="universe-boosters"]');
        // the claimable booster shows, the event one doesn't
        $this->assertStringContainsString('Pack Classique Univers', $boosters->text());
        $this->assertStringNotContainsString('Pack Event Univers', $boosters->text());

        $this->entityManager->persist(
            new UserBooster()
                ->setDiscordUser($this->user)
                ->setBooster($eventBooster)
                ->setQuantity(1),
        );
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/univers/' . $this->extension->getSlug());
        $boosters = $crawler->filter('[data-testid="universe-boosters"]');
        $this->assertStringContainsString('Pack Event Univers', $boosters->text());
        $this->assertStringContainsString('Non récupérable', $boosters->text());
    }

    public function testUnknownSlugIsNotFound(): void
    {
        $this->client->request('GET', '/univers/this-universe-does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDraftExtensionIsNotFound(): void
    {
        $draft = new Extension()
            ->setName('Univers draft ' . uniqid())
            ->setDescription('Pas encore publié')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        $this->client->request('GET', '/univers/' . $draft->getSlug());

        self::assertResponseStatusCodeSame(404);
    }

    public function testLegacyExtensionsRouteRedirectsToUniverses(): void
    {
        $this->client->request('GET', '/extensions');

        self::assertResponseRedirects('/univers', 301);
    }

    /**
     * One published extension with 3 published cards (+1 draft) — the user owns
     * the first one (×2, 1 holo) — and a claimable booster.
     */
    private function createScenario(): void
    {
        $this->extension = new Extension()
            ->setName('Univers test ' . uniqid())
            ->setDescription('Une description complète qui doit apparaître entière sur la page univers.')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);

        $this->cards[] = $this->createCard('Card U0 ' . uniqid(), CardRarityEnum::RARE);
        $this->cards[] = $this->createCard('Card U1 ' . uniqid());
        $this->cards[] = $this->createCard('Card U2 ' . uniqid());
        $this->createCard('Draft U ' . uniqid(), status: CardStatusEnum::DRAFT);

        $this->entityManager->persist(
            new UserCard()
                ->setDiscordUser($this->user)
                ->setCard($this->cards[0])
                ->setQuantity(2)
                ->setHoloQuantity(1),
        );

        $this->createBooster('Pack Classique Univers');

        $this->entityManager->flush();
    }

    private function createCard(string $name, CardRarityEnum $rarity = CardRarityEnum::COMMON, CardStatusEnum $status = CardStatusEnum::PUBLISHED): Card
    {
        $card = new Card()
            ->setName($name)
            ->setDescription('Test card')
            ->setStatus($status)
            ->setRarity($rarity)
            ->setExtension($this->extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        return $card;
    }

    private function createBooster(string $name, bool $claimable = true): Booster
    {
        $booster = new Booster()
            ->setExtension($this->extension)
            ->setName($name)
            ->setClaimable($claimable)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $this->entityManager->persist($booster);

        return $booster;
    }
}
