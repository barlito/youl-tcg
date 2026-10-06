<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Enum\Admin\AdminApiScopeEnum;
use App\Service\Admin\AdminApiTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class AdminApiTokenManagerTest extends TestCase
{
    private MockClock $clock;

    private ArrayAdapter $pool;

    private AdminApiTokenManager $manager;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-01 12:00:00', 'UTC');
        $this->pool = new ArrayAdapter();
        $this->manager = new AdminApiTokenManager($this->pool, $this->clock);
    }

    public function testGeneratedTokenIsValidForOneHourAndOnlyItsHashIsStored(): void
    {
        $generated = $this->manager->generate('42', [AdminApiScopeEnum::IMPORT]);

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
        $this->manager->generate('42', [AdminApiScopeEnum::IMPORT]);

        $this->assertFalse($this->manager->isValid('nope'));
        $this->assertFalse($this->manager->isValid(''));
    }

    public function testTokenExpiresAfterTheTtl(): void
    {
        $generated = $this->manager->generate('42', [AdminApiScopeEnum::IMPORT]);

        $this->clock->sleep(AdminApiTokenManager::TTL - 1);
        $this->assertTrue($this->manager->isValid($generated->token));

        $this->clock->sleep(1);
        $this->assertFalse($this->manager->isValid($generated->token));
        $this->assertNotInstanceOf(\App\Dto\Admin\AdminApiTokenInfo::class, $this->manager->activeTokenInfo());
    }

    public function testRegeneratingInvalidatesThePreviousToken(): void
    {
        $first = $this->manager->generate('42', [AdminApiScopeEnum::IMPORT]);
        $second = $this->manager->generate('43', [AdminApiScopeEnum::IMPORT]);

        $this->assertFalse($this->manager->isValid($first->token));
        $this->assertTrue($this->manager->isValid($second->token));
        $this->assertSame('43', $this->manager->activeTokenInfo()?->generatedBy);
    }

    public function testRevokeInvalidatesTheToken(): void
    {
        $generated = $this->manager->generate('42', [AdminApiScopeEnum::IMPORT]);
        $this->manager->revoke();

        $this->assertFalse($this->manager->isValid($generated->token));
        $this->assertNotInstanceOf(\App\Dto\Admin\AdminApiTokenInfo::class, $this->manager->activeTokenInfo());
    }

    public function testScopesAreStoredAndReturned(): void
    {
        $generated = $this->manager->generate('42', [AdminApiScopeEnum::STATS, AdminApiScopeEnum::IMPORT, AdminApiScopeEnum::STATS]);

        $this->assertSame([AdminApiScopeEnum::STATS, AdminApiScopeEnum::IMPORT], $generated->info->scopes);
        $info = $this->manager->validate($generated->token);
        $this->assertTrue($info?->hasScope(AdminApiScopeEnum::STATS));
        $this->assertTrue($info->hasScope(AdminApiScopeEnum::IMPORT));
    }

    public function testStatsOnlyTokenHasNoImportScope(): void
    {
        $generated = $this->manager->generate('42', [AdminApiScopeEnum::STATS]);

        $this->assertFalse($this->manager->validate($generated->token)?->hasScope(AdminApiScopeEnum::IMPORT));
    }

    public function testATokenWithoutScopeCannotBeGenerated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->generate('42', []);
    }

    public function testTokenStoredBeforeScopesExistedIsAnImportToken(): void
    {
        $item = $this->pool->getItem('import_api_token');
        $item->set(['hash' => hash('sha256', 'legacy'), 'generatedBy' => '42', 'expiresAt' => $this->clock->now()->getTimestamp() + 600]);
        $this->pool->save($item);

        $info = $this->manager->validate('legacy');

        $this->assertSame([AdminApiScopeEnum::IMPORT], $info?->scopes);
    }
}
