<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class HomepageTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // The homepage caches the day cards: without this, ids leak from one
        // test (or one local run) to the next.
        self::getContainer()->get('cache.app')->clear();
    }

    public function testHomepageShowsAllSections(): void
    {
        $this->authenticateClient($this->client);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#univers'));
        $this->assertCount(1, $crawler->filter('#raretes'));
        $this->assertCount(1, $crawler->filter('#roadmap'));
        $this->assertCount(1, $crawler->filter('[data-testid="ticker"]'));

        // The design uppercases through CSS: the DOM keeps the source case.
        $body = $crawler->filter('main')->text();
        $this->assertStringContainsString('PRESS START', $body);
        $this->assertStringContainsString('4 paliers.', $body);
        $this->assertStringContainsString('Prochain univers', $body);
    }

    public function testTickerShowsRealCounters(): void
    {
        $user = $this->authenticateClient($this->client);
        $this->createOpenings($user, 2);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $ticker = $crawler->filter('[data-testid="ticker"]')->text();
        $this->assertStringContainsString('2 packs ouverts', $ticker);
        // 2 openings × (2 normal + 1 holo): duplicates and holos all count
        $this->assertStringContainsString('6 cartes tirées', $ticker);
        $this->assertStringContainsString('univers', $ticker);
        $this->assertStringContainsString('2 packs / jour', $ticker);
    }

    public function testSoonTileTeasesTheUpcomingExtension(): void
    {
        $this->authenticateClient($this->client);

        $upcoming = new Extension()
            ->setName('Univers teasé ' . uniqid())
            ->setDescription('Encore secret')
            ->setStatus(ExtensionStatusEnum::DRAFT)
            ->setUpcoming(true)
        ;
        $upcoming->setImageName('teaser.png');
        $this->entityManager->persist($upcoming);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $tile = $crawler->filter('[data-testid="soon-tile"]');
        $this->assertCount(1, $tile);
        $this->assertStringContainsString($upcoming->getName(), $tile->text());
        $this->assertStringContainsString('Annonce à suivre', $tile->text());
        $this->assertStringContainsString('blur-lg', (string) $tile->filter('img')->attr('class'));
        // a draft universe has no page yet: the teaser must not link anywhere
        $this->assertCount(0, $tile->filter('a'));
        $this->assertStringContainsString('01 SOON', $crawler->filter('#univers')->text());
    }

    public function testSoonTileFallsBackToTheRarestCardArtwork(): void
    {
        $this->authenticateClient($this->client);

        // no extension image: the tile must pick the rarest card's artwork,
        // draft cards included (a teased universe is unpublished)
        $upcoming = new Extension()
            ->setName('Univers teasé sans image ' . uniqid())
            ->setDescription('Encore secret')
            ->setStatus(ExtensionStatusEnum::DRAFT)
            ->setUpcoming(true)
        ;
        $this->entityManager->persist($upcoming);
        foreach ([[CardRarityEnum::COMMON, 'teaser-common.png'], [CardRarityEnum::RARE, 'teaser-rare.png']] as [$rarity, $image]) {
            $card = new Card()
                ->setName('Teaser ' . $rarity->value . ' ' . uniqid())
                ->setDescription('Test')
                ->setExtension($upcoming)
                ->setStatus(CardStatusEnum::DRAFT)
                ->setRarity($rarity)
            ;
            $card->setImageName($image);
            $this->entityManager->persist($card);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $img = $crawler->filter('[data-testid="soon-tile"] img');
        $this->assertCount(1, $img);
        $this->assertStringContainsString('/uploads/cards/teaser-rare.png', (string) $img->attr('src'));
    }

    public function testUniverseGridOnlyShowsPublishedExtensions(): void
    {
        $this->authenticateClient($this->client);

        $draftExtension = new Extension()
            ->setName('Draft universe ' . uniqid())
            ->setDescription('Should stay hidden')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($draftExtension);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('#univers')->text();
        $this->assertStringContainsString('Cyberpunk 2077', $grid);
        $this->assertStringNotContainsString($draftExtension->getName(), $grid);
    }

    public function testLiveBadgeOnlyShowsWithAClaimableBooster(): void
    {
        $this->authenticateClient($this->client);

        // two fresh published universes: they take the newest featured slots
        $liveExtension = $this->createPublishedExtension('Univers live ' . uniqid());
        $this->entityManager->persist(
            new Booster()
                ->setExtension($liveExtension)
                ->setName('Pack Claimable Home')
                ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]]),
        );
        $silentExtension = $this->createPublishedExtension('Univers silencieux ' . uniqid());
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $badgeIn = static fn (Extension $extension): int => $crawler
            ->filterXPath(\sprintf(
                '//article[.//a[@href="/univers/%s"]]//*[@data-testid="live-badge"]',
                $extension->getSlug(),
            ))
            ->count()
        ;

        $this->assertSame(1, $badgeIn($liveExtension));
        $this->assertSame(0, $badgeIn($silentExtension));
    }

    private function createPublishedExtension(string $name): Extension
    {
        $extension = new Extension()
            ->setName($name)
            ->setDescription('Extension de test homepage')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        return $extension;
    }

    public function testHomepageSurvivesEmptyDayCards(): void
    {
        $this->authenticateClient($this->client);

        // Prime the cache as if no published card existed when it was built.
        $this->primeDayCardsCache([]);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testStaleDayCardsCacheIsRedrawn(): void
    {
        $this->authenticateClient($this->client);

        // Ids that no longer exist (e.g. fixtures reloaded since the cache
        // was built): the homepage must drop the cache and redraw.
        $this->primeDayCardsCache([(string) Uuid::v4(), (string) Uuid::v4(), (string) Uuid::v4()]);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertCount(3, $crawler->filter('.card'));
    }

    /**
     * @param list<string> $cardIds
     */
    private function primeDayCardsCache(array $cardIds): void
    {
        $pool = self::getContainer()->get('cache.app');
        \assert($pool instanceof CacheItemPoolInterface);
        $item = $pool->getItem('daycards');
        $item->set($cardIds);
        $pool->save($item);
    }

    private function createOpenings(DiscordUser $user, int $count): void
    {
        $extension = new Extension()
            ->setName('Homepage test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($extension);

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        // draft: the pulled card must not enter the homepage day-cards draw
        $card = new Card()
            ->setName('Homepage pulled card ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension)
            ->setStatus(CardStatusEnum::DRAFT)
            ->setRarity(CardRarityEnum::COMMON)
        ;
        $this->entityManager->persist($card);

        for ($i = 0; $i < $count; ++$i) {
            $opening = new BoosterOpening($user, $booster, $i + 1, new \DateTimeImmutable());
            $this->entityManager->persist($opening);
            $this->entityManager->persist(new BoosterOpeningCard($opening, $card, 2, 1));
        }

        $this->entityManager->flush();
    }
}
