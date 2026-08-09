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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class OpeningLuckStatsTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    private Extension $extension;

    private Booster $booster;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);

        $this->extension = new Extension()
            ->setName('Luck extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);

        // 2 cards per pack, advertised: common 75%, rare 25%, holo 10%
        $this->booster = $this->createBooster('Pack Chance ' . uniqid(), [
            ['rarities' => ['common' => 100], 'holoChance' => 0],
            ['rarities' => ['common' => 50, 'rare' => 50], 'holoChance' => 20],
        ]);
        $this->entityManager->flush();
    }

    public function testNoLuckSectionWithoutAnyOpening(): void
    {
        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="luck-section"]'));
        $this->assertStringContainsString('Aucune ouverture.', $crawler->filter('[data-testid="empty-state"]')->text());
    }

    public function testGlobalCountersAndRealHoloRate(): void
    {
        $this->createScenario();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $this->assertSame('4', $crawler->filter('[data-testid="luck-cards"]')->text(normalizeWhitespace: true));
        $this->assertStringContainsString('1', $crawler->filter('[data-testid="luck-holos"]')->text());
        // 1 holo out of 4 copies pulled
        $this->assertStringContainsString('25%', $crawler->filter('[data-testid="luck-tiles"]')->text());
        $this->assertSame('1', $crawler->filter('[data-testid="luck-legendaries"]')->text(normalizeWhitespace: true));
    }

    public function testRarityDistributionShowsEveryTierEvenEmpty(): void
    {
        $this->createScenario();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $distribution = $crawler->filter('[data-testid="luck-rarities"]')->text(normalizeWhitespace: true);
        $this->assertStringContainsString('Commune 3 cartes · 75%', $distribution);
        $this->assertStringContainsString('Légendaire 1 carte · 25%', $distribution);
        // tiers never pulled stay visible at zero
        $this->assertStringContainsString('Peu commune 0 carte · 0%', $distribution);
        $this->assertStringContainsString('Rare 0 carte · 0%', $distribution);
    }

    public function testBoosterPanelComparesObservedToAdvertisedRates(): void
    {
        $this->createScenario();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $panels = $crawler->filter('[data-testid="luck-booster"]');
        $this->assertCount(1, $panels);

        $panel = $panels->first()->text(normalizeWhitespace: true);
        $this->assertStringContainsString($this->booster->getDisplayName(), $panel);
        $this->assertStringContainsString('2 packs · 4 cartes', $panel);
        // observed vs the pack's advertised per-card averages
        $this->assertStringContainsString('Commune 75% / 75% annoncé', $panel);
        $this->assertStringContainsString('Rare 0% / 25% annoncé', $panel);
        // the legendary was never advertised but WAS pulled: the row shows up
        $this->assertStringContainsString('Légendaire 25% / 0% annoncé', $panel);
        $this->assertStringContainsString('✦ Holo 25% / 10% annoncé', $panel);
    }

    public function testBestPullIsTheEarliestOfTheRarestTier(): void
    {
        $this->createScenario();
        // a second, later legendary must not steal the tile
        $this->createOpening($this->booster, new \DateTimeImmutable('2026-03-15 10:00:00'), [
            ['name' => 'Légendaire tardive ' . uniqid(), 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1, 'holoQuantity' => 0],
        ]);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $tile = $crawler->filter('[data-testid="luck-best-pull"]')->text(normalizeWhitespace: true);
        $this->assertStringContainsString('Première légendaire', $tile);
        $this->assertStringContainsString('Youl doré', $tile);
        $this->assertStringContainsString('le 01/02/2026', $tile);
    }

    public function testBestPullFallsBackToTheRarestTierReached(): void
    {
        $this->createOpening($this->booster, new \DateTimeImmutable('2026-01-10 09:00:00'), [
            ['name' => 'Simple rare ' . uniqid(), 'rarity' => CardRarityEnum::RARE, 'quantity' => 1, 'holoQuantity' => 0],
            ['name' => 'Simple commune ' . uniqid(), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 1, 'holoQuantity' => 0],
        ]);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $tile = $crawler->filter('[data-testid="luck-best-pull"]')->text(normalizeWhitespace: true);
        $this->assertStringContainsString('Meilleur pull', $tile);
        $this->assertStringContainsString('Simple rare', $tile);
    }

    public function testBoostersAreOrderedByOpeningsAndForeignPullsAreIgnored(): void
    {
        $this->createScenario();

        // one opening of a second pack: it must list after the main one
        $secondBooster = $this->createBooster('Pack Secondaire ' . uniqid(), [['rarities' => ['common' => 100], 'holoChance' => 0]]);
        $this->createOpening($secondBooster, new \DateTimeImmutable('2026-02-05 10:00:00'), [
            ['name' => 'Commune secondaire ' . uniqid(), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 1, 'holoQuantity' => 0],
        ]);

        // someone else's legendary opening must not leak into the stats
        $otherUser = $this->entityManager->find(DiscordUser::class, '195659530363731968');
        \assert($otherUser instanceof DiscordUser);
        $this->createOpening($this->booster, new \DateTimeImmutable('2026-01-02 10:00:00'), [
            ['name' => 'Légendaire des autres ' . uniqid(), 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1, 'holoQuantity' => 1],
        ], $otherUser);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $this->assertSame('5', $crawler->filter('[data-testid="luck-cards"]')->text(normalizeWhitespace: true));
        $this->assertSame('1', $crawler->filter('[data-testid="luck-legendaries"]')->text(normalizeWhitespace: true));

        $names = $crawler->filter('[data-testid="luck-booster"]')->each(
            static fn (Crawler $node): string => $node->filter('p')->first()->text(normalizeWhitespace: true),
        );
        $this->assertSame([$this->booster->getDisplayName(), $secondBooster->getDisplayName()], $names);
    }

    /**
     * Two openings of the same pack:
     * - 10/01: 2 commons (one duplicated copy);
     * - 01/02: 1 common + 1 holo legendary « Youl doré ».
     * Totals: 4 copies, 1 holo (25%), common 75% / legendary 25%.
     */
    private function createScenario(): void
    {
        $this->createOpening($this->booster, new \DateTimeImmutable('2026-01-10 09:00:00'), [
            ['name' => 'Youl commun ' . uniqid(), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2, 'holoQuantity' => 0],
        ]);
        $this->createOpening($this->booster, new \DateTimeImmutable('2026-02-01 18:00:00'), [
            ['name' => 'Youl banal ' . uniqid(), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 1, 'holoQuantity' => 0],
            ['name' => 'Youl doré ' . uniqid(), 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1, 'holoQuantity' => 1],
        ]);
        $this->entityManager->flush();
    }

    /**
     * @param list<array{rarities: array<string, int>, holoChance: int}> $rarityRates
     */
    private function createBooster(string $name, array $rarityRates): Booster
    {
        $booster = new Booster()
            ->setName($name)
            ->setExtension($this->extension)
            ->setRarityRates($rarityRates)
        ;
        $this->entityManager->persist($booster);

        return $booster;
    }

    /**
     * @param list<array{name: string, rarity: CardRarityEnum, quantity: int, holoQuantity: int}> $drawnCards
     */
    private function createOpening(Booster $booster, \DateTimeImmutable $openedAt, array $drawnCards, ?DiscordUser $owner = null): void
    {
        $opening = new BoosterOpening($owner ?? $this->user, $booster, 1234, $openedAt);
        $this->entityManager->persist($opening);

        foreach ($drawnCards as $drawnCard) {
            $card = new Card()
                ->setName($drawnCard['name'])
                ->setDescription('Test card')
                ->setStatus(CardStatusEnum::PUBLISHED)
                ->setRarity($drawnCard['rarity'])
                ->setExtension($this->extension)
            ;
            $card->setImageName('default_card.png');
            $this->entityManager->persist($card);

            $openingCard = new BoosterOpeningCard($opening, $card, $drawnCard['quantity'], $drawnCard['holoQuantity']);
            $opening->addBoosterOpeningCard($openingCard);
            $this->entityManager->persist($openingCard);
        }
    }
}
