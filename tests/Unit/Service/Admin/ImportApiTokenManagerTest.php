<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\ImportApiTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ImportApiTokenManagerTest extends TestCase
{
    private MockClock $clock;

    private ArrayAdapter $pool;

    private ImportApiTokenManager $manager;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-01 12:00:00', 'UTC');
        $this->pool = new ArrayAdapter();
        $this->manager = new ImportApiTokenManager($this->pool, $this->clock);
    }

    public function testGeneratedTokenIsValidForOneHourAndOnlyItsHashIsStored(): void
    {
        $generated = $this->manager->generate('42');

        $this->assertGreaterThanOrEqual(43, \strlen($generated->token));
        $this->assertTrue($this->manager->isValid($generated->token));
        $this->assertSame('42', $this->manager->validate($generated->token)?->generatedBy);
        $this->assertSame('2026-10-01 13:00:00', $generated->info->expiresAt->format('Y-m-d H:i:s'));

        $stored = $this->pool->getItem('import_api_token')->get();
        $this->assertIsArray($stored);
        $this->assertSame(hash('sha256', $generated->token), $stored['hash']);
        $this->assertStringNotContainsString($generated->token, serialize($stored));
    }

    public function testWrongOrEmptyTokenIsRefused(): void
    {
        $this->manager->generate('42');

        $this->assertFalse($this->manager->isValid('nope'));
        $this->assertFalse($this->manager->isValid(''));
    }

    public function testTokenExpiresAfterTheTtl(): void
    {
        $generated = $this->manager->generate('42');

        $this->clock->sleep(ImportApiTokenManager::TTL - 1);
        $this->assertTrue($this->manager->isValid($generated->token));

        $this->clock->sleep(1);
        $this->assertFalse($this->manager->isValid($generated->token));
        $this->assertNotInstanceOf(\App\Dto\Admin\ImportTokenInfo::class, $this->manager->activeTokenInfo());
    }

    public function testRegeneratingInvalidatesThePreviousToken(): void
    {
        $first = $this->manager->generate('42');
        $second = $this->manager->generate('43');

        $this->assertFalse($this->manager->isValid($first->token));
        $this->assertTrue($this->manager->isValid($second->token));
        $this->assertSame('43', $this->manager->activeTokenInfo()?->generatedBy);
    }

    public function testRevokeInvalidatesTheToken(): void
    {
        $generated = $this->manager->generate('42');
        $this->manager->revoke();

        $this->assertFalse($this->manager->isValid($generated->token));
        $this->assertNotInstanceOf(\App\Dto\Admin\ImportTokenInfo::class, $this->manager->activeTokenInfo());
    }
}
