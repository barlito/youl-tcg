<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\RecycleSelectionLine;
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
use App\Exception\Recycle\BoosterNotRecyclableException;
use App\Exception\Recycle\InvalidRecycleSelectionException;
use App\Exception\Recycle\NotEnoughCopiesException;
use App\Exception\Recycle\NotEnoughRecyclePointsException;
use App\Service\Recycle\RecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecycleServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private RecycleService $recycleService;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->recycleService = self::getContainer()->get(RecycleService::class);
    }

    public function testRecyclingDebitsCopiesCreditsBoosterAndPersistsAudit(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]]);

        $operation = $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0)],
            $scenario['booster'],
        );

        $this->entityManager->clear();

        $userCard = $this->findUserCard($scenario['user'], $scenario['cards'][0]);
        $this->assertSame(2, $userCard->getQuantity());
        $this->assertSame(0, $userCard->getHoloQuantity());

        $userBooster = $this->entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $scenario['user']]);
        $this->assertNotNull($userBooster);
        $this->assertSame(1, $userBooster->getQuantity());

        $persisted = $this->entityManager->getRepository(RecycleOperation::class)->find($operation->getId());
        $this->assertNotNull($persisted);
        $this->assertSame(10, $persisted->getPoints());
        $this->assertSame(10, $persisted->getRecycledCardCount());
        $this->assertCount(1, $persisted->getRecycleOperationCards());
        $recycledCard = $persisted->getRecycleOperationCards()->first();
        $this->assertNotFalse($recycledCard);
        $this->assertSame(10, $recycledCard->getQuantity());
        $this->assertSame(0, $recycledCard->getHoloQuantity());
    }

    public function testRecyclingDoesNotConsumeTheDailyClaimQuota(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]]);

        $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0)],
            $scenario['booster'],
        );

        $this->assertSame(
            [],
            $this->entityManager->getRepository(\App\Entity\BoosterClaim::class)->findBy(['discordUser' => $scenario['user']]),
            'A recycle operation must not leave a BoosterClaim row (own distribution channel, quota untouched).',
        );
    }

    public function testHoloCopiesAreWorthTheirRarityPlusOne(): void
    {
        // 5 holo commons at 2 points each: exactly the 10-point cost
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 6, 'holoQuantity' => 5]]);

        $operation = $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 0, holoQuantity: 5)],
            $scenario['booster'],
        );

        $this->assertSame(10, $operation->getPoints());

        $this->entityManager->clear();

        $userCard = $this->findUserCard($scenario['user'], $scenario['cards'][0]);
        $this->assertSame(1, $userCard->getQuantity());
        $this->assertSame(0, $userCard->getHoloQuantity());
    }

    public function testMixedRaritiesAndKindsComputeTheRightTotal(): void
    {
        // 2 normal rares (6) + 1 holo uncommon (3) + 1 normal common (1) = 10
        $scenario = $this->createScenario([
            ['rarity' => CardRarityEnum::RARE, 'quantity' => 3],
            ['rarity' => CardRarityEnum::UNCOMMON, 'quantity' => 2, 'holoQuantity' => 2],
            ['rarity' => CardRarityEnum::COMMON, 'quantity' => 2],
        ]);

        $operation = $this->recycleService->recycle(
            $scenario['user'],
            [
                new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 2, holoQuantity: 0),
                new RecycleSelectionLine($scenario['cards'][1], normalQuantity: 0, holoQuantity: 1),
                new RecycleSelectionLine($scenario['cards'][2], normalQuantity: 1, holoQuantity: 0),
            ],
            $scenario['booster'],
        );

        $this->assertSame(10, $operation->getPoints());

        $this->entityManager->clear();

        $uncommon = $this->findUserCard($scenario['user'], $scenario['cards'][1]);
        $this->assertSame(1, $uncommon->getQuantity());
        $this->assertSame(1, $uncommon->getHoloQuantity(), 'Only one of the two holo copies is debited.');
    }

    public function testSurplusPointsBeyondTheCostAreLost(): void
    {
        // 3 legendaries = 15 points: one booster, the 5 extra points vanish
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 4]]);

        $operation = $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 3, holoQuantity: 0)],
            $scenario['booster'],
        );

        $this->assertSame(15, $operation->getPoints(), 'The audit keeps the real total, surplus included.');

        $this->entityManager->clear();

        $userBooster = $this->entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $scenario['user']]);
        $this->assertNotNull($userBooster);
        $this->assertSame(1, $userBooster->getQuantity(), 'Exactly ONE booster per operation, whatever the surplus.');
        $this->assertCount(1, $this->entityManager->getRepository(RecycleOperation::class)->findBy(['discordUser' => $scenario['user']]));
    }

    public function testRefusesASelectionBelowTheCost(): void
    {
        // 9 points: 3 rares
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::RARE, 'quantity' => 4]]);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 3, holoQuantity: 0)],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughRecyclePointsException');
        } catch (NotEnoughRecyclePointsException $exception) {
            $this->assertStringContainsString('9', $exception->getUserMessage());
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[4, 0]]);
    }

    public function testRefusesAnEmptySelection(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]]);

        $this->expectException(InvalidRecycleSelectionException::class);

        $this->recycleService->recycle($scenario['user'], [], $scenario['booster']);
    }

    public function testRefusesNegativeQuantities(): void
    {
        // a forged negative holo count must not become a credit
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 20]]);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 15, holoQuantity: -5)],
                $scenario['booster'],
            );
            $this->fail('Expected InvalidRecycleSelectionException');
        } catch (InvalidRecycleSelectionException) {
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[20, 0]]);
    }

    public function testRefusesAZeroQuantityLine(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]]);

        $this->expectException(InvalidRecycleSelectionException::class);

        $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 0, holoQuantity: 0)],
            $scenario['booster'],
        );
    }

    public function testRefusesDuplicateCardLines(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 30]]);

        $this->expectException(InvalidRecycleSelectionException::class);

        $this->recycleService->recycle(
            $scenario['user'],
            [
                new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 5, holoQuantity: 0),
                new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 5, holoQuantity: 0),
            ],
            $scenario['booster'],
        );
    }

    public function testTheLastCopyOfACardIsNeverRecyclable(): void
    {
        // 10 copies: recycling all 10 would empty the collection entry
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 10]]);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0)],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughCopiesException');
        } catch (NotEnoughCopiesException $exception) {
            $this->assertStringContainsString('au moins un exemplaire', $exception->getUserMessage());
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[10, 0]]);
    }

    public function testASingleCopyCardIsNotRecyclableAtAll(): void
    {
        // covers the 1/1 uniques: quantity 1 by construction, never recyclable
        $scenario = $this->createScenario([
            ['rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 1],
            ['rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 2],
        ]);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [
                    new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 1, holoQuantity: 0),
                    new RecycleSelectionLine($scenario['cards'][1], normalQuantity: 1, holoQuantity: 0),
                ],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughCopiesException');
        } catch (NotEnoughCopiesException) {
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[1, 0], [2, 0]]);
    }

    public function testRefusesMoreHoloCopiesThanOwned(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 4, 'holoQuantity' => 1]]);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 0, holoQuantity: 2)],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughCopiesException');
        } catch (NotEnoughCopiesException $exception) {
            $this->assertStringContainsString('holo', $exception->getUserMessage());
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[4, 1]]);
    }

    public function testRefusesNormalCopiesWhenOnlyHoloAreOwned(): void
    {
        // quantity is the TOTAL: 3 copies all holo means ZERO normal copies
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 3, 'holoQuantity' => 3]]);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 2, holoQuantity: 0)],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughCopiesException');
        } catch (NotEnoughCopiesException $exception) {
            $this->assertStringContainsString('normales', $exception->getUserMessage());
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[3, 3]]);
    }

    public function testRefusesACardTheUserDoesNotOwn(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]]);

        $stranger = new Card()
            ->setName('Never owned card')
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::LEGENDARY)
            ->setExtension($scenario['booster']->getExtension())
        ;
        $stranger->setImageName('default_card.png');
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        $this->expectException(NotEnoughCopiesException::class);

        $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($stranger, normalQuantity: 2, holoQuantity: 0)],
            $scenario['booster'],
        );
    }

    public function testRefusesANonClaimableBooster(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]], claimable: false);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0)],
                $scenario['booster'],
            );
            $this->fail('Expected BoosterNotRecyclableException');
        } catch (BoosterNotRecyclableException) {
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[12, 0]]);
    }

    public function testRefusesABoosterOfAnUnpublishedExtension(): void
    {
        $scenario = $this->createScenario(
            [['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]],
            extensionStatus: ExtensionStatusEnum::DRAFT,
        );

        $this->expectException(BoosterNotRecyclableException::class);

        $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0)],
            $scenario['booster'],
        );
    }

    public function testAFailingLineRollsBackTheWholeOperation(): void
    {
        // line 1 is valid and debited in memory before line 2 blows up: the
        // rollback must leave BOTH untouched, with no booster and no audit row
        $scenario = $this->createScenario([
            ['rarity' => CardRarityEnum::COMMON, 'quantity' => 12],
            ['rarity' => CardRarityEnum::COMMON, 'quantity' => 1],
        ]);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [
                    new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0),
                    new RecycleSelectionLine($scenario['cards'][1], normalQuantity: 1, holoQuantity: 0),
                ],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughCopiesException');
        } catch (NotEnoughCopiesException) {
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[12, 0], [1, 0]]);
    }

    public function testStaleQuantitiesAreRevalidatedUnderLock(): void
    {
        // valid at display time; a concurrent operation then eats the copies
        // behind the entity manager's back (raw SQL, like another request)
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]]);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_card SET quantity = 3 WHERE discord_user_id = :user AND card_id = :card',
            ['user' => $scenario['user']->getDiscordId(), 'card' => (string) $scenario['cards'][0]->getId()],
        );

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0)],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughCopiesException');
        } catch (NotEnoughCopiesException) {
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[3, 0]]);
    }

    /**
     * @param list<array{int, int}>                                         $expectedQuantities [quantity, holoQuantity] per scenario card
     * @param array{user: DiscordUser, booster: Booster, cards: list<Card>} $scenario
     */
    private function assertNothingChanged(array $scenario, array $expectedQuantities): void
    {
        $this->entityManager->clear();

        foreach ($expectedQuantities as $index => [$quantity, $holoQuantity]) {
            $userCard = $this->findUserCard($scenario['user'], $scenario['cards'][$index]);
            $this->assertSame($quantity, $userCard->getQuantity());
            $this->assertSame($holoQuantity, $userCard->getHoloQuantity());
        }

        $this->assertNull($this->entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $scenario['user']]));
        $this->assertSame([], $this->entityManager->getRepository(RecycleOperation::class)->findBy(['discordUser' => $scenario['user']]));
    }

    private function findUserCard(DiscordUser $user, Card $card): UserCard
    {
        $userCard = $this->entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $user, 'card' => $card]);
        $this->assertNotNull($userCard);

        return $userCard;
    }

    /**
     * @param list<array{rarity: CardRarityEnum, quantity: int, holoQuantity?: int}> $ownedCards
     *
     * @return array{user: DiscordUser, booster: Booster, cards: list<Card>}
     */
    private function createScenario(
        array $ownedCards,
        bool $claimable = true,
        ExtensionStatusEnum $extensionStatus = ExtensionStatusEnum::PUBLISHED,
    ): array {
        $extension = new Extension()
            ->setName('Recycle test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus($extensionStatus)
        ;
        $this->entityManager->persist($extension);

        $user = new DiscordUser()
            ->setDiscordId('recycle-test-' . uniqid())
            ->setUsername('Recycle tester')
        ;
        $this->entityManager->persist($user);

        $cards = [];
        foreach ($ownedCards as $index => $definition) {
            $card = new Card()
                ->setName(\sprintf('Recycle card %d %s', $index, uniqid()))
                ->setDescription('Test card')
                ->setStatus(CardStatusEnum::PUBLISHED)
                ->setRarity($definition['rarity'])
                ->setExtension($extension)
            ;
            $card->setImageName('default_card.png');
            $this->entityManager->persist($card);
            $cards[] = $card;

            $userCard = new UserCard()
                ->setDiscordUser($user)
                ->setCard($card)
                ->setQuantity($definition['quantity'])
                ->setHoloQuantity($definition['holoQuantity'] ?? 0)
            ;
            $this->entityManager->persist($userCard);
        }

        $booster = new Booster()
            ->setExtension($extension)
            ->setClaimable($claimable)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        $this->entityManager->flush();

        return ['user' => $user, 'booster' => $booster, 'cards' => $cards];
    }
}
