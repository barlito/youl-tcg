<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\RecycleOperation;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Twig\Components\RecycleHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
        $this->assertStringContainsString('4 / 10 pts', (string) $component->render());
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

        $this->assertStringContainsString('5 points de surplus perdus', (string) $component->component()->success);
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
