<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DiscordUser;
use App\Service\Booster\BoosterClaimQuotaInterface;
use App\Service\Booster\UnlimitedBoosterClaimQuota;
use PHPUnit\Framework\TestCase;

final class UnlimitedBoosterClaimQuotaTest extends TestCase
{
    public function testDisabledLimitAlwaysReportsFullQuotaWithoutHittingTheInnerQuota(): void
    {
        $inner = $this->createMock(BoosterClaimQuotaInterface::class);
        $inner->expects($this->never())->method('getRemainingClaims');

        $quota = new UnlimitedBoosterClaimQuota($inner, dailyLimitEnabled: false);

        $this->assertSame(BoosterClaimQuotaInterface::DAILY_LIMIT, $quota->getRemainingClaims(new DiscordUser()));
    }

    public function testEnabledLimitDelegatesToTheInnerQuota(): void
    {
        $user = new DiscordUser();
        $inner = $this->createMock(BoosterClaimQuotaInterface::class);
        $inner->expects($this->once())->method('getRemainingClaims')->with($user)->willReturn(1);

        $quota = new UnlimitedBoosterClaimQuota($inner, dailyLimitEnabled: true);

        $this->assertSame(1, $quota->getRemainingClaims($user));
    }

    public function testNextResetTimeAlwaysDelegates(): void
    {
        $resetTime = new \DateTimeImmutable('2026-06-11 00:00:00');
        $inner = $this->createStub(BoosterClaimQuotaInterface::class);
        $inner->method('getNextResetTime')->willReturn($resetTime);

        $quota = new UnlimitedBoosterClaimQuota($inner, dailyLimitEnabled: false);

        $this->assertSame($resetTime, $quota->getNextResetTime());
    }
}
