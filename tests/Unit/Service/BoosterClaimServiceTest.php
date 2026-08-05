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

final class BoosterClaimServiceTest extends TestCase
{
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
            $this->assertSame('Ce pack n\'est pas disponible.', $exception->getUserMessage());
        }
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

    /**
     * The claim path only uses the pure predicates, never the card
     * repository: a real service over a stubbed repository is enough.
     */
    private function availability(): BoosterAvailabilityService
    {
        return new BoosterAvailabilityService($this->createStub(CardRepository::class));
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
        return new Booster()->setExtension(
            new Extension()->setName('Published ext')->setStatus(ExtensionStatusEnum::PUBLISHED),
        );
    }
}
