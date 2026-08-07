<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Exception\Booster\BoosterCodeAlreadyRedeemedException;
use App\Exception\Booster\BoosterCodeExhaustedException;
use App\Exception\Booster\BoosterCodeExpiredException;
use App\Exception\Booster\BoosterCodeNotAvailableYetException;
use App\Exception\Booster\InvalidBoosterCodeException;
use App\Repository\BoosterCodeRedemptionRepository;
use App\Repository\BoosterCodeRepository;
use App\Repository\CardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Booster\BoosterCodeRedeemService;
use App\Service\Booster\UserInventoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BoosterCodeRedeemServiceTest extends TestCase
{
    private const string EXTENSION_ID = '0197c0de-0000-7000-8000-000000000001';

    private const string CODE = 'ABCDEFGHJKLM';

    public function testRedeemCreditsTheInventoryAndAuditsTheRedemption(): void
    {
        $clock = new MockClock('2026-08-08 12:00:00', 'UTC');
        $user = $this->user();
        $boosterCode = $this->code()->setQuantity(3);

        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->once())->method('creditBooster')->with($user, $boosterCode->getBooster(), 3);

        $entityManager = $this->entityManagerMock();
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $redemption = $this->service($boosterCode, $inventory, $entityManager, clock: $clock)->redeem($user, self::CODE);

        $this->assertSame($boosterCode, $redemption->getBoosterCode());
        $this->assertSame($user, $redemption->getDiscordUser());
        $this->assertSame(3, $redemption->getQuantity());
        $this->assertEquals($clock->now(), $redemption->getRedeemedAt());
        $this->assertSame(1, $boosterCode->getUses());
    }

    public function testRedeemNormalisesCaseAndSeparatorsBeforeLookup(): void
    {
        $boosterCode = $this->code();

        $codeRepository = $this->createMock(BoosterCodeRepository::class);
        $codeRepository->expects($this->once())->method('findOneForUpdate')->with(self::CODE)->willReturn($boosterCode);

        $service = new BoosterCodeRedeemService(
            $codeRepository,
            $this->redemptionRepository(false),
            $this->createStub(UserInventoryService::class),
            $this->availability(drawable: true),
            $this->entityManager(),
            new MockClock('2026-08-08 12:00:00', 'UTC'),
        );

        $service->redeem($this->user(), ' abcd-efgh jklm ');
    }

    public function testBlankInputIsRejectedWithoutHittingTheDatabase(): void
    {
        $codeRepository = $this->createMock(BoosterCodeRepository::class);
        $codeRepository->expects($this->never())->method('findOneForUpdate');

        $service = new BoosterCodeRedeemService(
            $codeRepository,
            $this->createStub(BoosterCodeRedemptionRepository::class),
            $this->createStub(UserInventoryService::class),
            $this->availability(drawable: true),
            $this->entityManager(),
            new MockClock('2026-08-08 12:00:00', 'UTC'),
        );

        $this->expectException(InvalidBoosterCodeException::class);

        $service->redeem($this->user(), '  --  ');
    }

    public function testUnknownCodeIsRefused(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $this->expectException(InvalidBoosterCodeException::class);

        $this->service(null, $inventory)->redeem($this->user(), self::CODE);
    }

    public function testRevokedCodeIsRefusedLikeAnUnknownOne(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        try {
            $this->service($this->code()->setDisabled(true), $inventory)->redeem($this->user(), self::CODE);
            $this->fail('Expected InvalidBoosterCodeException.');
        } catch (InvalidBoosterCodeException $exception) {
            $this->assertSame('Ce code n\'existe pas ou n\'est plus valide.', $exception->getUserMessage());
        }
    }

    public function testExpiredCodeIsRefused(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $boosterCode = $this->code()->setExpiresAt(new \DateTimeImmutable('2026-08-08 11:59:59', new \DateTimeZone('UTC')));

        $this->expectException(BoosterCodeExpiredException::class);

        $this->service($boosterCode, $inventory)->redeem($this->user(), self::CODE);
    }

    public function testCodeAlreadyRedeemedByThisPlayerIsRefused(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $boosterCode = $this->code()->setMaxUses(100);

        $this->expectException(BoosterCodeAlreadyRedeemedException::class);

        $this->service($boosterCode, $inventory, alreadyRedeemed: true)->redeem($this->user(), self::CODE);
    }

    public function testExhaustedCodeIsRefused(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $boosterCode = $this->code()->setMaxUses(1);
        $boosterCode->incrementUses();

        $this->expectException(BoosterCodeExhaustedException::class);

        $this->service($boosterCode, $inventory)->redeem($this->user(), self::CODE);
    }

    public function testUnlimitedCodeIsNeverExhausted(): void
    {
        $boosterCode = $this->code()->setMaxUses(null);
        foreach (range(1, 50) as $ignored) {
            $boosterCode->incrementUses();
        }

        $this->service($boosterCode)->redeem($this->user(), self::CODE);

        $this->assertSame(51, $boosterCode->getUses());
    }

    public function testUnpublishedExtensionBouncesWithoutBurningAUse(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $boosterCode = $this->code();
        $boosterCode->getBooster()->getExtension()->setStatus(ExtensionStatusEnum::DRAFT);

        try {
            $this->service($boosterCode, $inventory)->redeem($this->user(), self::CODE);
            $this->fail('Expected BoosterCodeNotAvailableYetException.');
        } catch (BoosterCodeNotAvailableYetException $exception) {
            $this->assertStringContainsString('pas encore disponible', $exception->getUserMessage());
        }

        $this->assertSame(0, $boosterCode->getUses());
    }

    public function testBoosterWithoutPublishedCardBouncesWithoutBurningAUse(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $boosterCode = $this->code();

        $this->expectException(BoosterCodeNotAvailableYetException::class);

        try {
            $this->service($boosterCode, $inventory, drawable: false)->redeem($this->user(), self::CODE);
        } finally {
            $this->assertSame(0, $boosterCode->getUses());
        }
    }

    private function service(
        ?BoosterCode $boosterCode = null,
        ?UserInventoryService $inventory = null,
        ?EntityManagerInterface $entityManager = null,
        bool $alreadyRedeemed = false,
        bool $drawable = true,
        ?MockClock $clock = null,
    ): BoosterCodeRedeemService {
        $codeRepository = $this->createStub(BoosterCodeRepository::class);
        $codeRepository->method('findOneForUpdate')->willReturn($boosterCode);

        return new BoosterCodeRedeemService(
            $codeRepository,
            $this->redemptionRepository($alreadyRedeemed),
            $inventory ?? $this->createStub(UserInventoryService::class),
            $this->availability($drawable),
            $entityManager ?? $this->entityManager(),
            $clock ?? new MockClock('2026-08-08 12:00:00', 'UTC'),
        );
    }

    private function redemptionRepository(bool $alreadyRedeemed): BoosterCodeRedemptionRepository
    {
        $repository = $this->createStub(BoosterCodeRedemptionRepository::class);
        $repository->method('existsFor')->willReturn($alreadyRedeemed);

        return $repository;
    }

    /**
     * @return EntityManagerInterface&MockObject
     */
    private function entityManagerMock(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function availability(bool $drawable): BoosterAvailabilityService
    {
        $cardRepository = $this->createStub(CardRepository::class);
        $cardRepository->method('findExtensionIdsWithPublishedCards')->willReturn($drawable ? [self::EXTENSION_ID] : []);

        return new BoosterAvailabilityService($cardRepository);
    }

    private function user(): DiscordUser
    {
        return new DiscordUser()->setDiscordId('188967649332428800');
    }

    private function code(): BoosterCode
    {
        $extension = new Extension()
            ->setName('Published ext')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $extension->setId(self::EXTENSION_ID);

        return new BoosterCode()
            ->setCode(self::CODE)
            ->setBooster(new Booster()->setExtension($extension))
        ;
    }
}
