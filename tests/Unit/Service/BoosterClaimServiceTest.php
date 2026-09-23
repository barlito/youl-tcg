<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booster;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Exception\Booster\BoosterNotClaimableException;
use App\Exception\Booster\DailyClaimLimitReachedException;
use App\Repository\CardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Booster\BoosterClaimQuotaInterface;
use App\Service\Booster\BoosterClaimService;
use App\Service\Booster\UserInventoryService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class BoosterClaimServiceTest extends TestCase
{
    private const string EXTENSION_ID = '0198f3a2-6c1e-7d4b-9a3f-2b8c5d7e9f10';

    public function testClaimCreditsTheInventoryAndPersistsAnAuditRow(): void
    {
        $clock = new MockClock('2026-06-10 12:00:00', 'UTC');
        $user = $this->user();
        $booster = $this->claimableBooster();

        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->once())->method('creditBooster')->with($user, $booster);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        // the quota check-then-insert must serialize on the user row
        $entityManager->expects($this->once())->method('find')
            ->with(DiscordUser::class, $user->getDiscordId(), LockMode::PESSIMISTIC_WRITE)
        ;
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $service = new BoosterClaimService($this->quotaWithRemaining(2), $inventory, $entityManager, $clock, $this->availability());

        $claim = $service->claim($user, $booster);

        $this->assertSame($user, $claim->getDiscordUser());
        $this->assertSame($booster, $claim->getBooster());
        $this->assertEquals($clock->now(), $claim->getClaimedAt());
    }

    public function testClaimThrowsOnceTheQuotaIsExhausted(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $service = new BoosterClaimService(
            $this->quotaWithRemaining(0),
            $inventory,
            $entityManager,
            new MockClock('2026-06-10 12:00:00', 'UTC'),
            $this->availability(),
        );

        $this->expectException(DailyClaimLimitReachedException::class);

        $service->claim($this->user(), $this->claimableBooster());
    }

    public function testClaimRefusesANonClaimableBooster(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $service = new BoosterClaimService(
            $this->quotaWithRemaining(2),
            $inventory,
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-06-10 12:00:00', 'UTC'),
            $this->availability(),
        );

        $this->expectException(BoosterNotClaimableException::class);

        $service->claim($this->user(), $this->claimableBooster()->setName('Pack Event')->setClaimable(false));
    }

    public function testClaimRefusesABoosterOfAnUnpublishedExtension(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $booster = new Booster()->setExtension(
            new Extension()->setName('Draft ext')->setStatus(ExtensionStatusEnum::DRAFT),
        );

        $service = new BoosterClaimService(
            $this->quotaWithRemaining(2),
            $inventory,
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-06-10 12:00:00', 'UTC'),
            $this->availability(),
        );

        try {
            $service->claim($this->user(), $booster);
            $this->fail('Expected BoosterNotClaimableException.');
        } catch (BoosterNotClaimableException $exception) {
            $this->assertSame('Ce pack n\'est pas récupérable pour le moment.', $exception->getUserMessage());
        }
    }

    public function testClaimRefusesABoosterWithNothingToDraw(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('wrapInTransaction');

        $service = new BoosterClaimService(
            $this->quotaWithRemaining(2),
            $inventory,
            $entityManager,
            new MockClock('2026-06-10 12:00:00', 'UTC'),
            $this->availability(drawable: false),
        );

        $this->expectException(BoosterNotClaimableException::class);

        $service->claim($this->user(), $this->claimableBooster());
    }

    public function testQuotaAccessorsDelegateToThePolicy(): void
    {
        $resetTime = new \DateTimeImmutable('2026-06-11 00:00:00');
        $quota = $this->createStub(BoosterClaimQuotaInterface::class);
        $quota->method('getRemainingClaims')->willReturn(1);
        $quota->method('getNextResetTime')->willReturn($resetTime);

        $service = new BoosterClaimService(
            $quota,
            $this->createStub(UserInventoryService::class),
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-06-10 12:00:00', 'UTC'),
            $this->availability(),
        );

        $this->assertSame(1, $service->getRemainingClaims(new DiscordUser()));
        $this->assertSame($resetTime, $service->getNextResetTime());
    }

    private function availability(bool $drawable = true): BoosterAvailabilityService
    {
        $cardRepository = $this->createStub(CardRepository::class);
        $cardRepository->method('findExtensionIdsWithPublishedCards')
            ->willReturn($drawable ? [self::EXTENSION_ID] : [])
        ;

        return new BoosterAvailabilityService($cardRepository);
    }

    private function quotaWithRemaining(int $remaining): BoosterClaimQuotaInterface
    {
        $quota = $this->createStub(BoosterClaimQuotaInterface::class);
        $quota->method('getRemainingClaims')->willReturn($remaining);

        return $quota;
    }

    private function user(): DiscordUser
    {
        return new DiscordUser()->setDiscordId('188967649332428800');
    }

    private function claimableBooster(): Booster
    {
        $extension = new Extension()->setName('Published ext')->setStatus(ExtensionStatusEnum::PUBLISHED);
        new \ReflectionProperty(Extension::class, 'id')->setValue($extension, Uuid::fromString(self::EXTENSION_ID));

        return new Booster()->setExtension($extension);
    }
}
