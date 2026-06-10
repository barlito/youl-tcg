<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booster;
use App\Entity\DiscordUser;
use App\Exception\Booster\DailyClaimLimitReachedException;
use App\Service\Booster\BoosterClaimQuotaInterface;
use App\Service\Booster\BoosterClaimService;
use App\Service\Booster\UserInventoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BoosterClaimServiceTest extends TestCase
{
    public function testClaimCreditsTheInventoryAndPersistsAnAuditRow(): void
    {
        $clock = new MockClock('2026-06-10 12:00:00', 'UTC');
        $user = new DiscordUser();
        $booster = new Booster();

        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->once())->method('creditBooster')->with($user, $booster);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $service = new BoosterClaimService($this->quotaWithRemaining(2), $inventory, $entityManager, $clock);

        $claim = $service->claim($user, $booster);

        $this->assertSame($user, $claim->getDiscordUser());
        $this->assertSame($booster, $claim->getBooster());
        $this->assertEquals($clock->now(), $claim->getClaimedAt());
    }

    public function testClaimThrowsOnceTheQuotaIsExhausted(): void
    {
        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $service = new BoosterClaimService(
            $this->quotaWithRemaining(0),
            $inventory,
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-06-10 12:00:00', 'UTC'),
        );

        $this->expectException(DailyClaimLimitReachedException::class);

        $service->claim(new DiscordUser(), new Booster());
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
        );

        $this->assertSame(1, $service->getRemainingClaims(new DiscordUser()));
        $this->assertSame($resetTime, $service->getNextResetTime());
    }

    private function quotaWithRemaining(int $remaining): BoosterClaimQuotaInterface
    {
        $quota = $this->createStub(BoosterClaimQuotaInterface::class);
        $quota->method('getRemainingClaims')->willReturn($remaining);

        return $quota;
    }
}
