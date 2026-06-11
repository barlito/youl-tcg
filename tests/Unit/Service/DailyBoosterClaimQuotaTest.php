<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DiscordUser;
use App\Repository\BoosterClaimRepository;
use App\Service\Booster\BoosterClaimQuotaInterface;
use App\Service\Booster\DailyBoosterClaimQuota;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DailyBoosterClaimQuotaTest extends TestCase
{
    public function testRemainingClaimsCountsDownFromTheDailyLimit(): void
    {
        $user = new DiscordUser();

        foreach ([0 => 2, 1 => 1, 2 => 0, 3 => 0] as $claimsToday => $expectedRemaining) {
            $quota = new DailyBoosterClaimQuota(
                $this->claimRepositoryCounting($claimsToday),
                new MockClock('2026-06-10 12:00:00', 'UTC'),
            );

            $this->assertSame($expectedRemaining, $quota->getRemainingClaims($user));
        }
    }

    public function testQuotaWindowStartsAtMidnightParisInWinter(): void
    {
        // 2026-01-15 23:30 UTC is already 2026-01-16 00:30 in Paris (UTC+1):
        // the window must start at 23:00 UTC = midnight Paris of the new day.
        $this->assertQuotaWindowStartsAt('2026-01-15 23:00:00', clockNow: '2026-01-15 23:30:00');
    }

    public function testQuotaWindowStartsAtMidnightParisInSummer(): void
    {
        // 2026-06-10 12:00 UTC = 14:00 Paris (UTC+2): the window must start
        // at 2026-06-09 22:00 UTC = midnight Paris the same day.
        $this->assertQuotaWindowStartsAt('2026-06-09 22:00:00', clockNow: '2026-06-10 12:00:00');
    }

    public function testNextResetTimeIsTheUpcomingMidnightParis(): void
    {
        $quota = new DailyBoosterClaimQuota(
            $this->claimRepositoryCounting(0),
            new MockClock('2026-06-10 12:00:00', 'UTC'),
        );

        $nextReset = $quota->getNextResetTime()->setTimezone(new \DateTimeZone('UTC'));

        // Midnight Paris on June 11th is 22:00 UTC on June 10th.
        $this->assertSame('2026-06-10 22:00:00', $nextReset->format('Y-m-d H:i:s'));
    }

    private function assertQuotaWindowStartsAt(string $expectedUtcWindowStart, string $clockNow): void
    {
        $claimRepository = $this->createMock(BoosterClaimRepository::class);
        $claimRepository->expects($this->once())
            ->method('countSince')
            ->with(
                $this->anything(),
                $this->callback(
                    static fn (\DateTimeImmutable $since): bool => $expectedUtcWindowStart === $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ),
            )
            ->willReturn(0)
        ;

        $quota = new DailyBoosterClaimQuota($claimRepository, new MockClock($clockNow, 'UTC'));

        $this->assertSame(BoosterClaimQuotaInterface::DAILY_LIMIT, $quota->getRemainingClaims(new DiscordUser()));
    }

    private function claimRepositoryCounting(int $claimsToday): BoosterClaimRepository
    {
        $claimRepository = $this->createStub(BoosterClaimRepository::class);
        $claimRepository->method('countSince')->willReturn($claimsToday);

        return $claimRepository;
    }
}
