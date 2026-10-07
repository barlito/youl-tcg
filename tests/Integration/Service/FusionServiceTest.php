<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\FusionOperation;
use App\Entity\MarketListing;
use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Exception\Fusion\FusionClosedException;
use App\Exception\Fusion\InvalidFusionCountException;
use App\Exception\Fusion\NotEnoughFusableCopiesException;
use App\Exception\Fusion\NotFusableCardException;
use App\Service\Fusion\FusionService;
use App\Tests\FeatureFlagTrait;
use App\Tests\Support\SpyHub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FusionServiceTest extends KernelTestCase
{
    use FeatureFlagTrait;

    private EntityManagerInterface $entityManager;

    private FusionService $fusionService;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fusionService = self::getContainer()->get(FusionService::class);
    }

    public function testTenNormalCopiesBecomeOneHolo(): void
    {
        $scenario = $this->createScenario(quantity: 12);

        $operation = $this->fusionService->fuse($scenario['user'], $scenario['card'], 1);

        $this->entityManager->clear();
        $userCard = $this->findUserCard($scenario['user'], $scenario['card']);
        $this->assertSame(3, $userCard->getQuantity(), '12 - 10 + 1');
        $this->assertSame(1, $userCard->getHoloQuantity());

        $persisted = $this->entityManager->getRepository(FusionOperation::class)->find($operation->getId());
        $this->assertNotNull($persisted);
        $this->assertSame(1, $persisted->getFusionCount());
        $this->assertSame(10, $persisted->getCopiesConsumed());
        $this->assertSame(1, $persisted->getHolosCreated());
        $this->assertSame($scenario['user']->getDiscordId(), $persisted->getDiscordUser()->getDiscordId());
    }

    public function testSeveralFusionsAtOnce(): void
    {
        $scenario = $this->createScenario(quantity: 35, holoQuantity: 2);

        $operation = $this->fusionService->fuse($scenario['user'], $scenario['card'], 3);

        $this->entityManager->clear();
        $userCard = $this->findUserCard($scenario['user'], $scenario['card']);
        $this->assertSame(35 - 30 + 3, $userCard->getQuantity());
        $this->assertSame(5, $userCard->getHoloQuantity());
        $this->assertSame(30, $operation->getCopiesConsumed());
        $this->assertSame(3, $operation->getHolosCreated());
    }

    public function testTheWholeNormalStockCanBeFused(): void
    {
        $scenario = $this->createScenario(quantity: 10);

        $this->fusionService->fuse($scenario['user'], $scenario['card'], 1);

        $this->entityManager->clear();
        $userCard = $this->findUserCard($scenario['user'], $scenario['card']);
        $this->assertSame(1, $userCard->getQuantity());
        $this->assertSame(1, $userCard->getHoloQuantity());
    }

    public function testAfterCommitTheInventoryChangedEventIsPublished(): void
    {
        $scenario = $this->createScenario(quantity: 10);
        $spy = self::getContainer()->get(SpyHub::class);
        $spy->reset();

        $this->fusionService->fuse($scenario['user'], $scenario['card'], 1);

        $this->assertSame('inventory-changed', $spy->getEvents()[0]['type']);
    }

    public function testNotEnoughCopiesIsRefusedAndNothingChanges(): void
    {
        $scenario = $this->createScenario(quantity: 9);

        $this->assertRefusedWithout($scenario, 1, NotEnoughFusableCopiesException::class, 9, 0);
    }

    public function testHoloCopiesAreNotNormalCopies(): void
    {
        // 12 copies in total, 3 of them holo: only 9 normal ones
        $scenario = $this->createScenario(quantity: 12, holoQuantity: 3);

        $this->assertRefusedWithout($scenario, 1, NotEnoughFusableCopiesException::class, 12, 3);
    }

    public function testMoreFusionsThanTheStockAllowsIsRefused(): void
    {
        $scenario = $this->createScenario(quantity: 25);

        $this->assertRefusedWithout($scenario, 3, NotEnoughFusableCopiesException::class, 25, 0);
    }

    public function testACardTheUserDoesNotOwnIsRefused(): void
    {
        $scenario = $this->createScenario(quantity: 10);
        $stranger = new DiscordUser()->setDiscordId('fusion-stranger-' . uniqid())->setUsername('Stranger');
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        $this->expectException(NotEnoughFusableCopiesException::class);
        $this->fusionService->fuse($stranger, $scenario['card'], 1);
    }

    public function testCopiesOfferedInAPendingOfferAreNotConsumed(): void
    {
        // 12 normal, 3 offered: 9 free
        $scenario = $this->createScenario(quantity: 12);
        $this->pendingOffer($scenario['user'], $scenario['card'], normal: 3);

        $this->assertRefusedWithout($scenario, 1, NotEnoughFusableCopiesException::class, 12, 0);
    }

    public function testFreeCopiesBesideAnOfferAreStillFusable(): void
    {
        // 14 normal, 3 offered: 11 free -> one fusion, the 3 offered copies remain
        $scenario = $this->createScenario(quantity: 14);
        $this->pendingOffer($scenario['user'], $scenario['card'], normal: 3);

        $this->fusionService->fuse($scenario['user'], $scenario['card'], 1);

        $this->entityManager->clear();
        $userCard = $this->findUserCard($scenario['user'], $scenario['card']);
        $this->assertSame(5, $userCard->getQuantity());
        $this->assertGreaterThanOrEqual(3, $userCard->getQuantity() - $userCard->getHoloQuantity(), 'The offered copies are still there.');
    }

    public function testCopiesListedOnTheMarketAreNotConsumed(): void
    {
        $scenario = $this->createScenario(quantity: 10);
        $this->entityManager->persist(new MarketListing($scenario['user'], $scenario['card'], false, 10));
        $this->entityManager->flush();

        $this->assertRefusedWithout($scenario, 1, NotEnoughFusableCopiesException::class, 10, 0);
    }

    public function testAListedHoloDoesNotLockTheNormalCopies(): void
    {
        $scenario = $this->createScenario(quantity: 11, holoQuantity: 1);
        $this->entityManager->persist(new MarketListing($scenario['user'], $scenario['card'], true, 10));
        $this->entityManager->flush();

        $this->fusionService->fuse($scenario['user'], $scenario['card'], 1);

        $this->entityManager->clear();
        $userCard = $this->findUserCard($scenario['user'], $scenario['card']);
        $this->assertSame(2, $userCard->getQuantity());
        $this->assertSame(2, $userCard->getHoloQuantity());
    }

    public function testACardRequestedFromThePlayerDoesNotLockHisCopies(): void
    {
        $scenario = $this->createScenario(quantity: 10);
        $this->pendingOffer($scenario['user'], $scenario['card'], normal: 5, asProposer: false, side: TradeOfferSideEnum::REQUESTED);

        $this->fusionService->fuse($scenario['user'], $scenario['card'], 1);

        $this->entityManager->clear();
        $this->assertSame(1, $this->findUserCard($scenario['user'], $scenario['card'])->getHoloQuantity());
    }

    public function testAUniqueCardCannotBeFused(): void
    {
        $scenario = $this->createScenario(quantity: 12);
        $scenario['card']->setUnique(true);
        $this->entityManager->flush();

        $this->assertRefusedWithout($scenario, 1, NotFusableCardException::class, 12, 0);
    }

    public function testADraftCardCannotBeFused(): void
    {
        $scenario = $this->createScenario(quantity: 12);
        $scenario['card']->setStatus(CardStatusEnum::DRAFT);
        $this->entityManager->flush();

        $this->assertRefusedWithout($scenario, 1, NotFusableCardException::class, 12, 0);
    }

    public function testACardOfAnUnpublishedUniverseCannotBeFused(): void
    {
        $scenario = $this->createScenario(quantity: 12, extensionStatus: ExtensionStatusEnum::DRAFT);

        $this->assertRefusedWithout($scenario, 1, NotFusableCardException::class, 12, 0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidCounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above the cap' => [FusionService::MAX_FUSIONS_PER_OPERATION + 1];
    }

    #[DataProvider('invalidCounts')]
    public function testAnInvalidFusionCountIsRefused(int $fusions): void
    {
        $scenario = $this->createScenario(quantity: 500);

        $this->assertRefusedWithout($scenario, $fusions, InvalidFusionCountException::class, 500, 0);
    }

    public function testTheCapIsAllowedExactly(): void
    {
        $scenario = $this->createScenario(quantity: 100);

        $operation = $this->fusionService->fuse($scenario['user'], $scenario['card'], FusionService::MAX_FUSIONS_PER_OPERATION);

        $this->assertSame(100, $operation->getCopiesConsumed());
    }

    public function testFusionIsRefusedWhileTheFeatureIsOff(): void
    {
        $scenario = $this->createScenario(quantity: 12);
        $this->setFeature(FeatureEnum::FUSION, false);

        try {
            $this->fusionService->fuse($scenario['user'], $scenario['card'], 1);
            $this->fail('Fusion must be refused while the feature is off.');
        } catch (FusionClosedException $exception) {
            $this->assertSame('La fusion est momentanément fermée.', $exception->getUserMessage());
        }

        $this->entityManager->clear();
        $this->assertSame(12, $this->findUserCard($scenario['user'], $scenario['card'])->getQuantity());
        $this->assertSame(0, $this->entityManager->getRepository(FusionOperation::class)->count(['discordUser' => $scenario['user']]));
    }

    public function testTheFreeCopyHelpersFollowTheQuantityRule(): void
    {
        $row = new UserCard()->setQuantity(25)->setHoloQuantity(4);

        $this->assertSame(21, FusionService::freeNormalCopies($row, null));
        $this->assertSame(16, FusionService::freeNormalCopies($row, ['normal' => 5, 'holo' => 2]));
        $this->assertSame(0, FusionService::freeNormalCopies($row, ['normal' => 99, 'holo' => 0]), 'Never negative.');
        $this->assertSame(2, FusionService::maxFusions(21));
        $this->assertSame(0, FusionService::maxFusions(9));
        $this->assertSame(FusionService::MAX_FUSIONS_PER_OPERATION, FusionService::maxFusions(10_000));
    }

    /**
     * @param array{user: DiscordUser, card: Card} $scenario
     * @param class-string<\Throwable>             $exception
     */
    private function assertRefusedWithout(array $scenario, int $fusions, string $exception, int $quantity, int $holo): void
    {
        try {
            $this->fusionService->fuse($scenario['user'], $scenario['card'], $fusions);
            $this->fail('Expected ' . $exception);
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown);
        }

        $this->assertTrue($this->entityManager->isOpen(), 'A refusal must not close the EntityManager.');
        $this->entityManager->clear();
        $userCard = $this->findUserCard($scenario['user'], $scenario['card']);
        $this->assertSame($quantity, $userCard->getQuantity());
        $this->assertSame($holo, $userCard->getHoloQuantity());
        $this->assertSame(0, $this->entityManager->getRepository(FusionOperation::class)->count(['discordUser' => $scenario['user']]));
    }

    private function pendingOffer(DiscordUser $player, Card $card, int $normal, bool $asProposer = true, TradeOfferSideEnum $side = TradeOfferSideEnum::OFFERED): TradeOffer
    {
        $other = new DiscordUser()->setDiscordId('fusion-other-' . uniqid())->setUsername('Other');
        $this->entityManager->persist($other);

        $offer = $asProposer
            ? new TradeOffer()->setProposer($player)->setReceiver($other)
            : new TradeOffer()->setProposer($other)->setReceiver($player);
        $offer->addLine(new TradeOfferLine()->setSide($side)->setCard($card)->setNormalQuantity($normal)->setHoloQuantity(0));
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
     * @return array{user: DiscordUser, card: Card}
     */
    private function createScenario(int $quantity, int $holoQuantity = 0, ExtensionStatusEnum $extensionStatus = ExtensionStatusEnum::PUBLISHED): array
    {
        $extension = new Extension()
            ->setName('Fusion test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus($extensionStatus)
        ;
        $this->entityManager->persist($extension);

        $user = new DiscordUser()->setDiscordId('fusion-test-' . uniqid())->setUsername('Fusion tester');
        $this->entityManager->persist($user);

        $card = new Card()
            ->setName('Fusion card ' . uniqid())
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($quantity)->setHoloQuantity($holoQuantity));
        $this->entityManager->flush();

        return ['user' => $user, 'card' => $card];
    }
}
