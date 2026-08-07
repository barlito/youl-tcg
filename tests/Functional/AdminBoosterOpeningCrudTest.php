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
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The opening screen is an audit tool: it must show what actually came out of
 * the pack, without ever offering a way to rewrite it.
 */
final class AdminBoosterOpeningCrudTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string CRUD_URL = '/admin/booster-opening';

    public function testDetailListsEveryDrawnCard(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $user = $this->authenticateClient($client);

        $opening = $this->createOpening($user, [
            ['name' => 'Zangetsu', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2, 'holoQuantity' => 1],
            ['name' => 'Ichigo', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1, 'holoQuantity' => 0, 'unique' => true],
        ]);

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $opening->getId());

        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('.opening-cards');
        $this->assertCount(1, $cards);

        $text = $cards->text();
        $this->assertStringContainsString('Ichigo', $text);
        $this->assertStringContainsString('Légendaire', $text);
        $this->assertStringContainsString('Zangetsu', $text);
        $this->assertStringContainsString('Commune', $text);
        // 3 copies drawn over 2 distinct cards, one of them holo
        $this->assertStringContainsString('3 exemplaires tirés', $text);
        $this->assertStringContainsString('2 cartes distinctes', $text);
        $this->assertStringContainsString('1 holo', $text);
        // the 1/1 badge flags the unique card
        $this->assertStringContainsString('1/1', $text);

        // rarest first: the legendary row comes before the common one
        $rows = $cards->filter('tbody tr');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Ichigo', $rows->eq(0)->text());
        $this->assertStringContainsString('Zangetsu', $rows->eq(1)->text());
    }

    public function testDetailLinksEachCardToItsAdminPage(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $user = $this->authenticateClient($client);

        $opening = $this->createOpening($user, [
            ['name' => 'Rukia', 'rarity' => CardRarityEnum::RARE, 'quantity' => 1, 'holoQuantity' => 0],
        ]);
        $cardId = $opening->getDrawnCards()[0]->getCard()->getId();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $opening->getId());

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('.opening-cards tbody a')->attr('href');
        $this->assertStringContainsString('/admin/card/' . $cardId, (string) $link);
    }

    public function testIndexShowsACompactSummary(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $user = $this->authenticateClient($client);

        $this->createOpening($user, [
            ['name' => 'Zangetsu', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2, 'holoQuantity' => 1],
            ['name' => 'Ichigo', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1, 'holoQuantity' => 0],
        ]);

        $crawler = $client->request('GET', self::CRUD_URL);

        self::assertResponseIsSuccessful();
        $summary = $crawler->filter('.opening-cards-summary');
        $this->assertCount(1, $summary);
        $this->assertStringContainsString('3 cartes', $summary->text());
        $this->assertStringContainsString('Légendaire', $summary->text());
        // the listing stays compact: no per-card table there
        $this->assertCount(0, $crawler->filter('.opening-cards'));
    }

    /**
     * The summary column reads the whole draw of every row, so the listing is the
     * natural place for an N+1. The query count must not follow the row count.
     */
    public function testTheListingDoesNotQueryPerRow(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client);

        $this->createOpening($user, $this->threeCards('a'));
        // warm-up: the first request shares the kernel — and the identity map —
        // of the test setup, which would hide one of the queries
        $client->request('GET', self::CRUD_URL);
        $queriesForOneOpening = $this->countListingQueries($client);

        $this->createOpening($user, $this->threeCards('b'));
        $this->createOpening($user, $this->threeCards('c'));

        $this->assertSame($queriesForOneOpening, $this->countListingQueries($client), 'Three openings must cost the same number of queries as one.');
    }

    public function testDetailOffersNoWriteAction(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $user = $this->authenticateClient($client);

        $opening = $this->createOpening($user, $this->threeCards('d'));
        $detailUrl = self::CRUD_URL . '/' . $opening->getId();

        $crawler = $client->request('GET', $detailUrl);
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.action-edit, .action-delete, .action-new'));

        $client->request('GET', $detailUrl . '/edit');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<array{name: string, rarity: CardRarityEnum, quantity: int, holoQuantity: int}>
     */
    private function threeCards(string $suffix): array
    {
        return [
            ['name' => 'Carte 1 ' . $suffix, 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2, 'holoQuantity' => 0],
            ['name' => 'Carte 2 ' . $suffix, 'rarity' => CardRarityEnum::RARE, 'quantity' => 1, 'holoQuantity' => 1],
            ['name' => 'Carte 3 ' . $suffix, 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1, 'holoQuantity' => 0],
        ];
    }

    /**
     * @param list<array{name: string, rarity: CardRarityEnum, quantity: int, holoQuantity: int, unique?: bool}> $drawnCards
     */
    private function createOpening(DiscordUser $user, array $drawnCards): BoosterOpening
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // the kernel reboots between requests: re-attach the owner to the
        // entity manager currently in use
        $owner = $entityManager->find(DiscordUser::class, $user->getDiscordId());
        \assert($owner instanceof DiscordUser);

        $extension = new Extension()
            ->setName('Opening crud extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $entityManager->persist($booster);

        $opening = new BoosterOpening($owner, $booster, 1234, new \DateTimeImmutable());
        $entityManager->persist($opening);

        foreach ($drawnCards as $drawnCard) {
            $card = new Card()
                ->setName($drawnCard['name'])
                ->setDescription('Test')
                ->setExtension($extension)
                ->setStatus(CardStatusEnum::PUBLISHED)
                ->setRarity($drawnCard['rarity'])
                ->setUnique($drawnCard['unique'] ?? false)
            ;
            $entityManager->persist($card);

            $openingCard = new BoosterOpeningCard($opening, $card, $drawnCard['quantity'], $drawnCard['holoQuantity']);
            $opening->addBoosterOpeningCard($openingCard);
            $entityManager->persist($openingCard);
        }

        $entityManager->flush();

        return $opening;
    }

    /**
     * The debug data holder accumulates every query of the connection, fixtures
     * included: it has to be emptied so only the listing request is counted.
     */
    private function countListingQueries(KernelBrowser $client): int
    {
        $dataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        \assert($dataHolder instanceof DebugDataHolder);
        $dataHolder->reset();

        $client->enableProfiler();
        $client->request('GET', self::CRUD_URL);
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        $this->assertNotFalse($profile, 'The profiler must be enabled to count the queries.');

        $collector = $profile->getCollector('db');
        $this->assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }
}
