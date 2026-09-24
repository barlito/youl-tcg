<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\RecycleSelectionLine;
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
use App\Enum\FeatureEnum;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Exception\Recycle\BoosterNotRecyclableException;
use App\Exception\Recycle\InvalidRecycleSelectionException;
use App\Exception\Recycle\NotEnoughCopiesException;
use App\Exception\Recycle\NotEnoughRecyclePointsException;
use App\Exception\Recycle\RecyclingClosedException;
use App\Service\Recycle\RecycleService;
use App\Tests\FeatureFlagTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecycleServiceTest extends KernelTestCase
{
    use FeatureFlagTrait;

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
        $this->assertSame(1, $persisted->getBoosterCount());
        $this->assertSame(10, $persisted->getRecycledCardCount());
        $this->assertCount(1, $persisted->getRecycleOperationCards());
        $recycledCard = $persisted->getRecycleOperationCards()->first();
        $this->assertNotFalse($recycledCard);
        $this->assertSame(10, $recycledCard->getQuantity());
        $this->assertSame(0, $recycledCard->getHoloQuantity());
    }

    public function testRecyclingIsRefusedWhileTheFeatureIsOff(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]]);
        $this->setFeature(FeatureEnum::RECYCLING, false);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0)],
                $scenario['booster'],
            );
            $this->fail('Recycling must be refused while the feature is off.');
        } catch (RecyclingClosedException $exception) {
            $this->assertSame('Le recyclage est momentanément fermé.', $exception->getUserMessage());
        }

        $this->entityManager->clear();
        $this->assertSame(12, $this->findUserCard($scenario['user'], $scenario['cards'][0])->getQuantity());
        $this->assertSame(0, $this->entityManager->getRepository(RecycleOperation::class)->count(['discordUser' => $scenario['user']]));
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

    /**
     * @return iterable<string, array{int, int}> common copies recycled => boosters expected
     */
    public static function tranches(): iterable
    {
        yield '10 points, exactly one tranche' => [10, 1];
        yield '19 points, the 9 extra are lost' => [19, 1];
        yield '20 points, two tranches' => [20, 2];
        yield '35 points, three tranches and 5 lost' => [35, 3];
    }

    #[DataProvider('tranches')]
    public function testEachFullTrancheOfPointsIsWorthOneBooster(int $points, int $expectedBoosters): void
    {
        // commons are worth 1 point each: the copy count is the point total
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => $points + 1]]);

        $operation = $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: $points, holoQuantity: 0)],
            $scenario['booster'],
        );

        $this->entityManager->clear();

        $userBoosters = $this->entityManager->getRepository(UserBooster::class)->findBy(['discordUser' => $scenario['user']]);
        $this->assertCount(1, $userBoosters, 'Every booster granted is a copy of the single chosen pack.');
        $this->assertSame((string) $scenario['booster']->getId(), (string) $userBoosters[0]->getBooster()->getId());
        $this->assertSame($expectedBoosters, $userBoosters[0]->getQuantity());

        $persisted = $this->entityManager->getRepository(RecycleOperation::class)->findBy(['discordUser' => $scenario['user']]);
        $this->assertCount(1, $persisted, 'One operation, whatever the number of boosters.');
        $this->assertSame((string) $operation->getId(), (string) $persisted[0]->getId());
        $this->assertSame($points, $persisted[0]->getPoints(), 'The audit keeps the real total, surplus included.');
        $this->assertSame($expectedBoosters, $persisted[0]->getBoosterCount());
        $this->assertSame((string) $scenario['booster']->getId(), (string) $persisted[0]->getBooster()->getId());
        $this->assertSame(1, $this->findUserCard($scenario['user'], $scenario['cards'][0])->getQuantity());
    }

    public function testBoostersAddUpWithTheOnesAlreadyOwned(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::LEGENDARY, 'quantity' => 5]]);
        $this->entityManager->persist(new UserBooster()->setDiscordUser($scenario['user'])->setBooster($scenario['booster'])->setQuantity(2));
        $this->entityManager->flush();

        // 4 legendaries = 20 points = 2 boosters, on top of the 2 owned
        $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 4, holoQuantity: 0)],
            $scenario['booster'],
        );

        $this->entityManager->clear();

        $userBooster = $this->entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $scenario['user']]);
        $this->assertNotNull($userBooster);
        $this->assertSame(4, $userBooster->getQuantity());
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

    public function testACardOfferedInAPendingOfferIsNotRecyclableAtAll(): void
    {
        // 12 uncommons, a single one engaged: the 10 spare duplicates are locked too
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::UNCOMMON, 'quantity' => 12]]);
        $this->pendingOffer($scenario['user'], $scenario['cards'][0], asProposer: true, side: TradeOfferSideEnum::OFFERED);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 5, holoQuantity: 0)],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughCopiesException');
        } catch (NotEnoughCopiesException $exception) {
            $this->assertStringContainsString('engagée dans une offre d\'échange en attente', $exception->getUserMessage());
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[12, 0]]);
    }

    public function testAnEngagedHoloLocksTheNormalCopiesToo(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::RARE, 'quantity' => 8, 'holoQuantity' => 3]]);
        $this->pendingOffer($scenario['user'], $scenario['cards'][0], asProposer: true, side: TradeOfferSideEnum::OFFERED, normal: 0, holo: 1);

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 4, holoQuantity: 0)],
                $scenario['booster'],
            );
            $this->fail('Expected NotEnoughCopiesException');
        } catch (NotEnoughCopiesException) {
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[8, 3]]);
    }

    public function testACardRequestedFromThePlayerIsNotRecyclable(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::UNCOMMON, 'quantity' => 12]]);
        $this->pendingOffer($scenario['user'], $scenario['cards'][0], asProposer: false, side: TradeOfferSideEnum::REQUESTED);

        $this->expectException(NotEnoughCopiesException::class);

        $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 5, holoQuantity: 0)],
            $scenario['booster'],
        );
    }

    public function testACardThePlayerAsksForOrIsOfferedStaysRecyclable(): void
    {
        // the player's own request and another player's offer take nothing from their stock
        $scenario = $this->createScenario([
            ['rarity' => CardRarityEnum::UNCOMMON, 'quantity' => 6],
            ['rarity' => CardRarityEnum::UNCOMMON, 'quantity' => 6],
        ]);
        $this->pendingOffer($scenario['user'], $scenario['cards'][0], asProposer: true, side: TradeOfferSideEnum::REQUESTED);
        $this->pendingOffer($scenario['user'], $scenario['cards'][1], asProposer: false, side: TradeOfferSideEnum::OFFERED);

        $operation = $this->recycleService->recycle(
            $scenario['user'],
            [
                new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 5, holoQuantity: 0),
                new RecycleSelectionLine($scenario['cards'][1], normalQuantity: 5, holoQuantity: 0),
            ],
            $scenario['booster'],
        );

        $this->assertSame(10, $operation->getRecycledCardCount());
    }

    public function testAResolvedOfferLocksNothing(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::UNCOMMON, 'quantity' => 12]]);
        $offer = $this->pendingOffer($scenario['user'], $scenario['cards'][0], asProposer: true, side: TradeOfferSideEnum::OFFERED, normal: 4);
        $offer->resolve(TradeOfferStatusEnum::CANCELLED, new \DateTimeImmutable());
        $this->entityManager->flush();

        $operation = $this->recycleService->recycle(
            $scenario['user'],
            [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 11, holoQuantity: 0)],
            $scenario['booster'],
        );

        $this->assertSame(11, $operation->getRecycledCardCount());
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

    public function testRefusesABoosterWithNothingToDraw(): void
    {
        $scenario = $this->createScenario([['rarity' => CardRarityEnum::COMMON, 'quantity' => 12]]);

        // published extension without any published card: claimable, yet unopenable
        $emptyExtension = new Extension()
            ->setName('Recycle empty extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($emptyExtension);
        $emptyBooster = new Booster()
            ->setExtension($emptyExtension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $emptyBooster->setImageName('default_card.png');
        $this->entityManager->persist($emptyBooster);
        $this->entityManager->flush();

        try {
            $this->recycleService->recycle(
                $scenario['user'],
                [new RecycleSelectionLine($scenario['cards'][0], normalQuantity: 10, holoQuantity: 0)],
                $emptyBooster,
            );
            $this->fail('Expected BoosterNotRecyclableException');
        } catch (BoosterNotRecyclableException) {
        }

        $this->assertNothingChanged($scenario, expectedQuantities: [[12, 0]]);
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

    /**
     * A pending offer between $player and a fresh counterpart, holding one
     * line on $side for $card.
     */
    private function pendingOffer(DiscordUser $player, Card $card, bool $asProposer, TradeOfferSideEnum $side, int $normal = 1, int $holo = 0): TradeOffer
    {
        $other = new DiscordUser()->setDiscordId('recycle-other-' . uniqid())->setUsername('Other');
        $this->entityManager->persist($other);

        $offer = $asProposer
            ? new TradeOffer()->setProposer($player)->setReceiver($other)
            : new TradeOffer()->setProposer($other)->setReceiver($player);
        $offer->addLine(new TradeOfferLine()->setSide($side)->setCard($card)->setNormalQuantity($normal)->setHoloQuantity($holo));
        $this->entityManager->persist($offer);
        $this->entityManager->flush();

        return $offer;
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
