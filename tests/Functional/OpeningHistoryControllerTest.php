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

final class OpeningHistoryControllerTest extends WebTestCase
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
            ->setName('History extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);

        $this->booster = new Booster()
            ->setName('Pack Journal ' . uniqid())
            ->setExtension($this->extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $this->entityManager->persist($this->booster);
        $this->entityManager->flush();
    }

    public function testRedirectsWhenNotAuthenticated(): void
    {
        // drop the jwt cookie set by setUp: anonymous visit
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/mes-ouvertures');

        $this->assertContains($this->client->getResponse()->getStatusCode(), [302, 307]);
    }

    public function testEmptyStateWhenNoOpening(): void
    {
        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('0', $crawler->filter('[data-testid="openings-total"]')->text());
        $this->assertStringContainsString('Aucune ouverture.', $crawler->filter('[data-testid="empty-state"]')->text());
        $this->assertCount(0, $crawler->filter('[data-testid="openings-list"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="pagination"]'));
    }

    public function testOpeningsAreListedNewestFirstWithTheirMeta(): void
    {
        $old = $this->createOpening(new \DateTimeImmutable('2026-01-05 10:00:00'), [
            ['name' => 'Vieille commune ' . uniqid(), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2, 'holoQuantity' => 0],
        ]);
        $recent = $this->createOpening(new \DateTimeImmutable('2026-02-20 18:30:00'), [
            ['name' => 'Commune récente ' . uniqid(), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 1, 'holoQuantity' => 0],
            ['name' => 'Légendaire récente ' . uniqid(), 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1, 'holoQuantity' => 1],
        ]);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('2', $crawler->filter('[data-testid="openings-total"]')->text());

        $entries = $crawler->filter('[data-testid="opening-entry"]');
        $this->assertCount(2, $entries);

        // Newest first: the recent opening leads.
        $first = $entries->first();
        $this->assertStringContainsString($this->booster->getDisplayName(), $first->filter('h2')->text());
        $this->assertStringContainsString('20/02/2026', $first->text());
        $this->assertStringContainsString('2 cartes', $first->text());
        $this->assertStringContainsString('Légendaire', $first->filter('[data-testid="best-rarity"]')->text());
        $this->assertStringContainsString('1 holo', $first->filter('[data-testid="holo-count"]')->text());

        $last = $entries->last();
        $this->assertStringContainsString('05/01/2026', $last->text());
        $this->assertStringContainsString('2 cartes', $last->text());
        $this->assertCount(0, $last->filter('[data-testid="holo-count"]'));

        // Rendered through CardComponent: the 3D controller is wired on each card.
        $this->assertGreaterThan(0, $first->filter('.card[data-controller="card"]')->count());
    }

    public function testCardTilesCarryQuantityHoloAndBestPullChips(): void
    {
        $legendaryName = 'Best pull ' . uniqid();
        $this->createOpening(new \DateTimeImmutable('2026-03-01 12:00:00'), [
            ['name' => 'Doublon commun ' . uniqid(), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2, 'holoQuantity' => 1],
            ['name' => $legendaryName, 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1, 'holoQuantity' => 0],
        ]);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $entry = $crawler->filter('[data-testid="opening-entry"]')->first();

        // drawnCards sorts rarest first: the legendary opens the grid and wears the chip.
        $firstTile = $entry->filter('.card-tile')->first();
        $this->assertCount(1, $firstTile->filter(\sprintf('img[alt="%s"]', $legendaryName)));
        $this->assertStringContainsString('Best', $firstTile->filter('[data-testid="best-pull"]')->text());
        $this->assertCount(1, $entry->filter('[data-testid="best-pull"]'));

        // The duplicated holo common shows both counters and renders its holo variant.
        $duplicateTile = $entry->filter('.card-tile')->last();
        $this->assertStringContainsString('×2', $duplicateTile->text());
        $this->assertStringContainsString('✦1', $duplicateTile->text());
        $this->assertCount(1, $duplicateTile->filter('.card.holo'));
    }

    public function testUnpublishedCardsStayInTheJournal(): void
    {
        $draftName = 'Carte dépubliée ' . uniqid();
        $this->createOpening(new \DateTimeImmutable('2026-03-02 12:00:00'), [
            ['name' => $draftName, 'rarity' => CardRarityEnum::RARE, 'quantity' => 1, 'holoQuantity' => 0, 'status' => CardStatusEnum::DRAFT],
        ]);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(\sprintf('img[alt="%s"]', $draftName)));
    }

    public function testOnlyOwnOpeningsAreListed(): void
    {
        $otherUser = $this->entityManager->find(DiscordUser::class, '195659530363731968');
        \assert($otherUser instanceof DiscordUser);

        $foreignName = 'Carte des autres ' . uniqid();
        $this->createOpening(new \DateTimeImmutable('2026-03-03 12:00:00'), [
            ['name' => $foreignName, 'rarity' => CardRarityEnum::COMMON, 'quantity' => 1, 'holoQuantity' => 0],
        ], $otherUser);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('0', $crawler->filter('[data-testid="openings-total"]')->text());
        $this->assertCount(0, $crawler->filter(\sprintf('img[alt="%s"]', $foreignName)));
    }

    public function testHistoryIsPaginated(): void
    {
        // 13 openings: one over the page size of 12
        for ($i = 1; $i <= 13; ++$i) {
            $this->createOpening(new \DateTimeImmutable(\sprintf('2026-01-%02d 09:00:00', $i)), [
                ['name' => \sprintf('Carte %02d %s', $i, uniqid()), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 1, 'holoQuantity' => 0],
            ]);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');
        self::assertResponseIsSuccessful();
        $this->assertCount(12, $crawler->filter('[data-testid="opening-entry"]'));
        // Page 1 holds the 13th..2nd openings; the oldest is pushed to page 2.
        $this->assertStringContainsString('13/01/2026', $crawler->filter('[data-testid="opening-entry"]')->first()->text());
        $this->assertStringContainsString('page 1 / 2', $crawler->filter('[data-testid="pagination"]')->text());
        $this->assertCount(1, $crawler->filter('[data-testid="page-next"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="page-prev"]'));

        $crawler = $this->client->request('GET', '/mes-ouvertures?page=2');
        self::assertResponseIsSuccessful();
        $entries = $crawler->filter('[data-testid="opening-entry"]');
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('01/01/2026', $entries->first()->text());
        $this->assertCount(0, $crawler->filter('[data-testid="page-next"]'));
        // the way back links to the bare url (no ?page=1 duplicate)
        $this->assertSame('/mes-ouvertures', $crawler->filter('[data-testid="page-prev"]')->attr('href'));
    }

    public function testOutOfRangePagesAreNotFound(): void
    {
        $this->createOpening(new \DateTimeImmutable('2026-03-04 12:00:00'), [
            ['name' => 'Carte seule ' . uniqid(), 'rarity' => CardRarityEnum::COMMON, 'quantity' => 1, 'holoQuantity' => 0],
        ]);
        $this->entityManager->flush();

        $this->client->request('GET', '/mes-ouvertures?page=0');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/mes-ouvertures?page=2');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSameCardInTwoOpeningsKeepsDomIdsUnique(): void
    {
        $card = $this->createCard('Carte partagée ' . uniqid(), CardRarityEnum::COMMON);

        foreach (['2026-03-05 10:00:00', '2026-03-06 10:00:00'] as $openedAt) {
            $opening = new BoosterOpening($this->user, $this->booster, 1234, new \DateTimeImmutable($openedAt));
            $this->entityManager->persist($opening);
            $openingCard = new BoosterOpeningCard($opening, $card, 1, 0);
            $opening->addBoosterOpeningCard($openingCard);
            $this->entityManager->persist($openingCard);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/mes-ouvertures');

        self::assertResponseIsSuccessful();
        $ids = $crawler->filter('[data-testid="opening-entry"] .card')->extract(['id']);
        $this->assertCount(2, $ids);
        $this->assertSame($ids, array_unique($ids));
    }

    /**
     * @param list<array{name: string, rarity: CardRarityEnum, quantity: int, holoQuantity: int, status?: CardStatusEnum}> $drawnCards
     */
    private function createOpening(\DateTimeImmutable $openedAt, array $drawnCards, ?DiscordUser $owner = null): BoosterOpening
    {
        $opening = new BoosterOpening($owner ?? $this->user, $this->booster, 1234, $openedAt);
        $this->entityManager->persist($opening);

        foreach ($drawnCards as $drawnCard) {
            $card = $this->createCard($drawnCard['name'], $drawnCard['rarity'], $drawnCard['status'] ?? CardStatusEnum::PUBLISHED);
            $openingCard = new BoosterOpeningCard($opening, $card, $drawnCard['quantity'], $drawnCard['holoQuantity']);
            $opening->addBoosterOpeningCard($openingCard);
            $this->entityManager->persist($openingCard);
        }

        return $opening;
    }

    private function createCard(string $name, CardRarityEnum $rarity, CardStatusEnum $status = CardStatusEnum::PUBLISHED): Card
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
}
