<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\RecycleOperation;
use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Twig\Components\RecycleHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class RecycleHubComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    // Juju owns no fixture cards or boosters: every scenario starts clean.
    private const string USER = '195659530363731968';

    public function testThePageListsOnlyDuplicates(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $scenario = $this->createScenario($user, [
            ['name' => 'Dup card', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3, 'holoQuantity' => 1],
            ['name' => 'Single card', 'rarity' => CardRarityEnum::RARE, 'quantity' => 1],
        ]);

        $client->request('GET', '/recyclage');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('data-testid="recycle-grid"', $content);
        $this->assertStringContainsString($scenario['cards'][0]->getName(), $content);
        $this->assertStringNotContainsString($scenario['cards'][1]->getName(), $content, 'A card without duplicates is not recyclable.');
    }

    public function testOnlyThePublishedCatalogueIsListed(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $scenario = $this->createScenario($user, [
            ['name' => 'Published dup', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3],
            ['name' => 'Draft dup', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3],
        ]);
        $scenario['cards'][1]->setStatus(CardStatusEnum::DRAFT);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/recyclage');

        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString($scenario['cards'][0]->getName(), $content);
        $this->assertStringNotContainsString($scenario['cards'][1]->getName(), $content, 'A draft card is not listed.');
    }

    public function testTheEmptyStateShowsWithoutAnyDuplicate(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER);

        $client->request('GET', '/recyclage');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('data-testid="recycle-empty"', (string) $client->getResponse()->getContent());
    }

    public function testTheCollectionPageLinksToTheRecyclePage(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER);

        $client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('data-testid="recycle-link"', (string) $client->getResponse()->getContent());
    }

    public function testAddCopyClampsToTheRecyclableCopies(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        // 3 copies: only 2 are recyclable, the last one always stays
        $scenario = $this->createScenario($user, [['name' => 'Clamp card', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        foreach (range(1, 5) as $ignored) {
            $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        }

        $this->assertSame(['normal' => 2, 'holo' => 0], $component->component()->selection[$cardId]);
    }

    public function testAddCopyRespectsTheHoloSubCountAndTheKeepOneRule(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        // quantity 4 with 1 holo: 3 recyclable in total, at most 1 of them holo
        $scenario = $this->createScenario($user, [['name' => 'Holo cap card', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 4, 'holoQuantity' => 1]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        foreach (range(1, 3) as $ignored) {
            $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'holo']);
        }
        foreach (range(1, 5) as $ignored) {
            $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        }

        // 1 holo (sub-count cap) then 2 normal (keep-one total cap), 4 points
        $this->assertSame(['normal' => 2, 'holo' => 1], $component->component()->selection[$cardId]);
        $this->assertStringContainsString('Encore 6 pts pour 1 booster', (string) $component->render());
    }

    public function testACardEngagedInATradeOfferIsListedButLocked(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $scenario = $this->createScenario($user, [
            ['name' => 'Offered card', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 5],
            ['name' => 'Requested card', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 4],
            ['name' => 'Free card', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3],
        ]);
        // a single copy offered is enough to lock every duplicate; a card requested from the player locks nothing
        $this->engage($user, $scenario['cards'][0], asProposer: true, side: TradeOfferSideEnum::OFFERED);
        $this->engage($user, $scenario['cards'][1], asProposer: false, side: TradeOfferSideEnum::REQUESTED);

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        foreach ([0, 1] as $index) {
            $component->call('addCopy', ['cardId' => (string) $scenario['cards'][$index]->getId(), 'kind' => 'normal']);
        }

        $this->assertSame([(string) $scenario['cards'][1]->getId()], array_keys($component->component()->selection), 'The requested card stays selectable.');
        $crawler = new Crawler((string) $component->render());
        $this->assertSame('5', trim($crawler->filter('[data-testid="recyclable-total"] p')->eq(1)->text()), 'The requested and the free cards count.');
        $engaged = $crawler->filter('[data-testid="recycle-card"][data-engaged="true"]');
        $this->assertCount(1, $engaged);
        $this->assertStringContainsString('⇄ engagée dans un échange', $engaged->text());
        $this->assertCount(0, $engaged->filter('[data-testid="add-normal"]'), 'A locked card has no selection control.');
    }

    public function testRemoveCopyDropsTheLineAtZero(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $scenario = $this->createScenario($user, [['name' => 'Remove card', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->call('removeCopy', ['cardId' => $cardId, 'kind' => 'normal']);

        $this->assertSame([], $component->component()->selection);
    }

    public function testRecyclingCreditsTheChosenBoosterAndDebitsTheCopies(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        // 2 recyclable legendaries = exactly the 10-point cost
        $scenario = $this->createScenario($user, [['name' => 'Legendary dup', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 3]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->set('boosterId', (string) $scenario['booster']->getId());
        $component->call('recycle');

        $this->assertNull($component->component()->error);
        $this->assertStringContainsString('ajouté à ton stock', (string) $component->component()->success);
        $this->assertSame([], $component->component()->selection, 'The selection is emptied after a successful operation.');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $userBooster = $entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $user, 'booster' => $scenario['booster']]);
        $this->assertNotNull($userBooster);
        $this->assertSame(1, $userBooster->getQuantity());

        $userCard = $entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $user, 'card' => $scenario['cards'][0]]);
        $this->assertNotNull($userCard);
        $this->assertSame(1, $userCard->getQuantity());

        $operations = $entityManager->getRepository(RecycleOperation::class)->findBy(['discordUser' => $user]);
        $this->assertCount(1, $operations);
        $this->assertSame(10, $operations[0]->getPoints());
    }

    public function testTheSurplusIsAnnouncedInTheSuccessMessage(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        // 3 recyclable legendaries = 15 points: 5 lost
        $scenario = $this->createScenario($user, [['name' => 'Surplus dup', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 4]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        foreach (range(1, 3) as $ignored) {
            $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        }
        $component->set('boosterId', (string) $scenario['booster']->getId());
        $component->call('recycle');

        $this->assertStringContainsString('1 pack « ', (string) $component->component()->success);
        $this->assertStringContainsString('5 points perdus', (string) $component->component()->success);
    }

    public function testASelectionBelowTheCostIsRefused(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $scenario = $this->createScenario($user, [['name' => 'Cheap dup', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->set('boosterId', (string) $scenario['booster']->getId());
        $component->call('recycle');

        $this->assertStringContainsString('points', (string) $component->component()->error);
        $this->assertNull($component->component()->success);
        $this->assertNull(
            static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(UserBooster::class)
                ->findOneBy(['discordUser' => $user, 'booster' => $scenario['booster']]),
        );
    }

    public function testConfirmingWithoutABoosterAsksForOne(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $scenario = $this->createScenario($user, [['name' => 'No booster dup', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 3]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->call('recycle');

        $this->assertSame('Choisis le pack à récupérer en échange.', $component->component()->error);
    }

    public function testANonClaimableBoosterIsNeitherListedNorAccepted(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $scenario = $this->createScenario($user, [['name' => 'Event dup', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 3]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $eventBooster = new Booster()
            ->setName('Event only pack ' . uniqid())
            ->setExtension($scenario['booster']->getExtension())
            ->setClaimable(false)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $eventBooster->setImageName('default_card.png');
        $entityManager->persist($eventBooster);
        $entityManager->flush();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);

        $this->assertStringNotContainsString($eventBooster->getName() ?? '', (string) $component->render(), 'Event packs are not offered.');

        // a forged id must be refused server-side, not just hidden in the UI
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->set('boosterId', (string) $eventBooster->getId());
        $component->call('recycle');

        $this->assertStringContainsString('recyclage', (string) $component->component()->error);
        $this->assertNull(
            $entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $user, 'booster' => $eventBooster]),
        );
    }

    public function testTwentyPointsGrantTwoCopiesOfTheChosenBooster(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        // 4 recyclable legendaries = 20 points = 2 tranches
        $scenario = $this->createScenario($user, [['name' => 'Double dup', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 5]]);
        $cardId = (string) $scenario['cards'][0]->getId();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        foreach (range(1, 4) as $ignored) {
            $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        }
        $component->set('boosterId', (string) $scenario['booster']->getId());

        $crawler = $component->render()->crawler();
        $this->assertStringContainsString('→ 2 boosters', $crawler->filter('[data-testid="recycle-outcome"]')->text());
        $this->assertStringContainsString('Recycler contre 2 packs', $crawler->filter('[data-testid="recycle-confirm"]')->text());

        $component->call('recycle');

        $this->assertNull($component->component()->error);
        $this->assertStringContainsString('2 packs « ', (string) $component->component()->success);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $userBooster = $entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $user, 'booster' => $scenario['booster']]);
        $this->assertNotNull($userBooster);
        $this->assertSame(2, $userBooster->getQuantity());

        $operations = $entityManager->getRepository(RecycleOperation::class)->findBy(['discordUser' => $user]);
        $this->assertCount(1, $operations);
        $this->assertSame(2, $operations[0]->getBoosterCount());
    }

    public function testTheLeftoverPointsAreAnnouncedBeforeConfirming(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        // 23 points: 3 legendaries (15) + 2 rares (6) + 2 commons (2)
        $scenario = $this->createScenario($user, [
            ['name' => 'Leftover legendary', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 4],
            ['name' => 'Leftover rare', 'rarity' => CardRarityEnum::RARE, 'quantity' => 3],
            ['name' => 'Leftover common', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3],
        ]);

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        foreach ([[0, 3], [1, 2], [2, 2]] as [$index, $copies]) {
            foreach (range(1, $copies) as $ignored) {
                $component->call('addCopy', ['cardId' => (string) $scenario['cards'][$index]->getId(), 'kind' => 'normal']);
            }
        }

        $crawler = $component->render()->crawler();
        $this->assertStringContainsString('23', $crawler->filter('[data-testid="points-counter"]')->text());
        $this->assertStringContainsString('→ 2 boosters, 3 pts perdus', $crawler->filter('[data-testid="recycle-outcome"]')->text());
    }

    public function testThePointsBarIsStickyAndHoldsThePackChoiceAndTheConfirmButton(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $scenario = $this->createScenario($user, [['name' => 'Sticky dup', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3]]);

        $crawler = $this->createLiveComponent(RecycleHub::class, client: $client)->render()->crawler();

        $bar = $crawler->filter('[data-testid="recycle-bar"]');
        $this->assertCount(1, $bar);
        $this->assertStringContainsString('sticky', (string) $bar->attr('class'));
        $this->assertCount(1, $bar->filter('[data-testid="points-counter"]'));
        $this->assertCount(1, $bar->filter('select[data-model="boosterId"] option[value="' . $scenario['booster']->getId() . '"]'), 'The pack choice is a compact select inside the bar.');
        $this->assertCount(1, $bar->filter('[data-testid="recycle-confirm"]'));
    }

    public function testEachTileHighlightsItsRecyclableCopiesAndThePageTheirTotal(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        // 4 copies incl. 1 holo: 3 recyclable, up to 3 normal / 1 holo; 2 copies all holo: 1 recyclable holo
        $this->createScenario($user, [
            ['name' => 'Badge mixed', 'rarity' => CardRarityEnum::RARE, 'quantity' => 4, 'holoQuantity' => 1],
            ['name' => 'Badge holo only', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2, 'holoQuantity' => 2],
        ]);

        $client->request('GET', '/recyclage');
        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

        $badges = $crawler->filter('[data-testid="recyclable-badge"]')->each(static fn ($node): string => trim($node->text()));
        $this->assertSame(['♻ 3 recyclables', '♻ 1 recyclable'], $badges, 'Rarest first: the rare then the common.');

        $mixed = $crawler->filter('[data-testid="recycle-card"]')->eq(0);
        $this->assertSame('0/3', $mixed->filter('[data-testid="normal-count"]')->text());
        $this->assertSame('0/1', $mixed->filter('[data-testid="holo-count"]')->text());
        $holoOnly = $crawler->filter('[data-testid="recycle-card"]')->eq(1);
        $this->assertCount(0, $holoOnly->filter('[data-testid="normal-count"]'), 'No normal copy owned: no normal counter.');
        $this->assertSame('0/1', $holoOnly->filter('[data-testid="holo-count"]')->text());

        $total = $crawler->filter('[data-testid="recyclable-total"]')->text();
        $this->assertStringContainsString('4', $total);
        $this->assertStringContainsString('exemplaires recyclables', $total);
        // best pick keeps the cheapest copy: 2 normal rares + 1 holo rare (6 + 4) + 1 holo common (2)
        $this->assertStringContainsString('Jusqu\'à 12 pts', $total);
    }

    public function testTheCardsAreGroupedAndFilterableByUniverse(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $first = $this->createScenario($user, [['name' => 'Universe A dup', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 3]]);
        $second = $this->createScenario($user, [['name' => 'Universe B dup', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2]]);
        $firstExtension = $first['booster']->getExtension();
        $secondExtension = $second['booster']->getExtension();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        $crawler = $component->render()->crawler();

        // most recyclable universe first, one strip tile each plus « Tout »
        $this->assertCount(2, $crawler->filter('[data-testid="recycle-universe"]'));
        $this->assertStringContainsString($firstExtension->getName(), $crawler->filter('[data-testid="recycle-universe"]')->eq(0)->text());
        $this->assertCount(2, $crawler->filter('[data-testid="strip-tile"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="strip-tile-all"][data-carousel-active]'));

        $component->call('filterUniverse', ['slug' => $secondExtension->getSlug()]);
        $crawler = $component->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="recycle-universe"]'));
        $this->assertStringContainsString($second['cards'][0]->getName(), $crawler->filter('[data-testid="recycle-grid"]')->text());
        $this->assertStringNotContainsString($first['cards'][0]->getName(), $crawler->filter('[data-testid="recycle-grid"]')->text());
        $this->assertCount(1, $crawler->filter('[data-testid="strip-tile"][data-carousel-active]'));

        $component->call('filterUniverse', ['slug' => '']);
        $this->assertCount(2, $component->render()->crawler()->filter('[data-testid="recycle-universe"]'));
    }

    public function testTheUniverseFilterIsReadFromTheUrl(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $first = $this->createScenario($user, [['name' => 'Url A dup', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2]]);
        $second = $this->createScenario($user, [['name' => 'Url B dup', 'rarity' => CardRarityEnum::COMMON, 'quantity' => 2]]);

        $client->request('GET', '/recyclage?univers=' . $second['booster']->getExtension()->getSlug());

        self::assertResponseIsSuccessful();
        $grid = $client->getCrawler()->filter('[data-testid="recycle-grid"]');
        $this->assertCount(1, $grid);
        $this->assertStringContainsString($second['cards'][0]->getName(), $grid->text());
        $this->assertStringNotContainsString($first['cards'][0]->getName(), $grid->text());
    }

    public function testTheSelectionSurvivesAUniverseSwitch(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $first = $this->createScenario($user, [['name' => 'Keep A dup', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 2]]);
        $second = $this->createScenario($user, [['name' => 'Keep B dup', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 2]]);

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        $component->call('addCopy', ['cardId' => (string) $first['cards'][0]->getId(), 'kind' => 'normal']);
        $component->call('filterUniverse', ['slug' => $second['booster']->getExtension()->getSlug()]);
        $component->call('addCopy', ['cardId' => (string) $second['cards'][0]->getId(), 'kind' => 'normal']);

        $this->assertCount(2, $component->component()->selection);
        $this->assertStringContainsString('→ 1 booster', $component->render()->crawler()->filter('[data-testid="recycle-outcome"]')->text());
    }

    public function testTheCollectionNavEntryIsActiveOnTheRecyclePage(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER);

        $client->request('GET', '/recyclage');

        self::assertResponseIsSuccessful();
        $nav = $client->getCrawler()->filter('header nav');
        $this->assertStringContainsString('active', (string) $nav->filter('a[href="/collection"]')->attr('class'));
        $this->assertStringNotContainsString('active', (string) $nav->filter('a[href="/boosters"]')->attr('class'));
    }

    public function testABoosterWithNothingToDrawIsNotOffered(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $this->createScenario($user, [['name' => 'Empty pack dup', 'rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 3]]);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $emptyExtension = new Extension()
            ->setName('Recycle empty extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($emptyExtension);
        $emptyBooster = new Booster()
            ->setName('Empty pack ' . uniqid())
            ->setExtension($emptyExtension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $emptyBooster->setImageName('default_card.png');
        $entityManager->persist($emptyBooster);
        $entityManager->flush();

        $component = $this->createLiveComponent(RecycleHub::class, client: $client);

        $this->assertStringNotContainsString($emptyBooster->getName() ?? '', (string) $component->render(), 'A pack without drawable card is not offered.');
    }

    private function engage(DiscordUser $player, Card $card, bool $asProposer, TradeOfferSideEnum $side): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $other = new DiscordUser()->setDiscordId('recycle-hub-other-' . uniqid())->setUsername('Other');
        $entityManager->persist($other);

        $offer = $asProposer
            ? new TradeOffer()->setProposer($player)->setReceiver($other)
            : new TradeOffer()->setProposer($other)->setReceiver($player);
        $offer->addLine(new TradeOfferLine()->setSide($side)->setCard($card)->setNormalQuantity(1)->setHoloQuantity(0));
        $entityManager->persist($offer);
        $entityManager->flush();
    }

    /**
     * @param list<array{name: string, rarity: CardRarityEnum, quantity: int, holoQuantity?: int}> $ownedCards
     *
     * @return array{booster: Booster, cards: list<Card>}
     */
    private function createScenario(DiscordUser $user, array $ownedCards): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $extension = new Extension()
            ->setName('Recycle hub extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        $cards = [];
        foreach ($ownedCards as $definition) {
            $card = new Card()
                ->setName($definition['name'] . ' ' . uniqid())
                ->setDescription('Test card')
                ->setStatus(CardStatusEnum::PUBLISHED)
                ->setRarity($definition['rarity'])
                ->setExtension($extension)
            ;
            $card->setImageName('default_card.png');
            $entityManager->persist($card);
            $cards[] = $card;

            $userCard = new UserCard()
                ->setDiscordUser($user)
                ->setCard($card)
                ->setQuantity($definition['quantity'])
                ->setHoloQuantity($definition['holoQuantity'] ?? 0)
            ;
            $entityManager->persist($userCard);
        }

        $booster = new Booster()
            ->setName('Recycle pack ' . uniqid())
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $entityManager->persist($booster);

        $entityManager->flush();

        return ['booster' => $booster, 'cards' => $cards];
    }
}
