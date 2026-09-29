<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Service\Security\TokenRoleMapper;
use PHPUnit\Framework\TestCase;

final class TokenRoleMapperTest extends TestCase
{
    private TokenRoleMapper $mapper;

    #[\Override]
    protected function setUp(): void
    {
        $this->mapper = new TokenRoleMapper();
    }

    public function testAppPrefixIsStrippedFromTokenRoles(): void
    {
        $this->assertSame(['ROLE_ADMIN'], $this->mapper->fromToken(['ROLE_YTCG_ADMIN']));
    }

    public function testTheCoinAdminRoleGrantsNothing(): void
    {
        $this->assertSame([], $this->mapper->fromToken(['ROLE_ADMIN']));
    }

    public function testOtherRolesAndBarePrefixAreIgnored(): void
    {
        $roles = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_OTHERAPP_ADMIN', 'ROLE_YTCG_', 'ROLE_YTCG_ADMIN', 'ROLE_YTCG_ADMIN'];

        $this->assertSame(['ROLE_ADMIN'], $this->mapper->fromToken($roles));
    }

    public function testAppRolesAreMappedBackToTokenRoles(): void
    {
        $this->assertSame(['ROLE_YTCG_ADMIN'], $this->mapper->toToken(['ROLE_USER', 'ROLE_ADMIN']));
    }

    public function testRoundTrip(): void
    {
        $this->assertSame(['ROLE_ADMIN'], $this->mapper->fromToken($this->mapper->toToken(['ROLE_ADMIN'])));
    }
}
