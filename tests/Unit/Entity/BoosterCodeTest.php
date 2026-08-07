<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BoosterCode;
use PHPUnit\Framework\TestCase;

final class BoosterCodeTest extends TestCase
{
    public function testFormattedCodeIsGroupedInFours(): void
    {
        $this->assertSame('ABCD-EFGH-JKLM', new BoosterCode()->setCode('ABCDEFGHJKLM')->getFormattedCode());
    }

    public function testExhaustionFollowsMaxUses(): void
    {
        $code = new BoosterCode()->setMaxUses(2);

        $this->assertFalse($code->isExhausted());
        $this->assertSame(2, $code->getRemainingUses());

        $code->incrementUses();
        $this->assertFalse($code->isExhausted());
        $this->assertSame(1, $code->getRemainingUses());

        $code->incrementUses();
        $this->assertTrue($code->isExhausted());
        $this->assertSame(0, $code->getRemainingUses());
    }

    public function testUnlimitedCodeHasNoRemainingCount(): void
    {
        $code = new BoosterCode()->setMaxUses(null);
        $code->incrementUses();

        $this->assertFalse($code->isExhausted());
        $this->assertNull($code->getRemainingUses());
    }

    public function testExpiryIsStoredInUtcWhateverTheInputTimezone(): void
    {
        // Doctrine binds datetimes without converting them: a 22:00 Paris expiry
        // stored as-is would be compared against a UTC now and last two extra hours
        $code = new BoosterCode()->setExpiresAt(
            new \DateTimeImmutable('2026-08-08 22:00:00', new \DateTimeZone('Europe/Paris')),
        );

        $this->assertSame('2026-08-08 20:00:00', $code->getExpiresAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $code->getExpiresAt()?->getTimezone()->getName());
    }

    public function testExpiryBoundaryIsInclusive(): void
    {
        $expiresAt = new \DateTimeImmutable('2026-08-08 20:00:00', new \DateTimeZone('UTC'));
        $code = new BoosterCode()->setExpiresAt($expiresAt);

        $this->assertFalse($code->isExpired($expiresAt->modify('-1 second')));
        $this->assertTrue($code->isExpired($expiresAt));
    }

    public function testCodeWithoutExpiryNeverExpires(): void
    {
        $this->assertFalse(new BoosterCode()->isExpired(new \DateTimeImmutable('2099-01-01')));
    }

    public function testRedeemabilityCombinesRevocationExpiryAndQuota(): void
    {
        $now = new \DateTimeImmutable('2026-08-08 12:00:00', new \DateTimeZone('UTC'));

        $this->assertTrue(new BoosterCode()->isRedeemable($now));
        $this->assertFalse(new BoosterCode()->setDisabled(true)->isRedeemable($now));
        $this->assertFalse(new BoosterCode()->setExpiresAt($now->modify('-1 day'))->isRedeemable($now));

        $exhausted = new BoosterCode()->setMaxUses(1);
        $exhausted->incrementUses();
        $this->assertFalse($exhausted->isRedeemable($now));
    }

    public function testBatchLabelIsTrimmedAndBlankBecomesNull(): void
    {
        $this->assertSame('Gamescom', new BoosterCode()->setBatchLabel('  Gamescom  ')->getBatchLabel());
        $this->assertNull(new BoosterCode()->setBatchLabel('   ')->getBatchLabel());
        $this->assertNull(new BoosterCode()->setBatchLabel(null)->getBatchLabel());
    }
}
