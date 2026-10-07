<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\Admin\AdminApiScopeEnum;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Principal of the admin API: bound to the admin who generated the token, but
 * carries only one role per granted scope (never the admin's own roles).
 */
final readonly class AdminApiUser implements UserInterface
{
    /**
     * @param list<AdminApiScopeEnum> $scopes
     */
    public function __construct(private string $generatedBy, private array $scopes)
    {
    }

    public function getGeneratedBy(): string
    {
        return $this->generatedBy;
    }

    public function getRoles(): array
    {
        return array_map(static fn (AdminApiScopeEnum $scope): string => $scope->role(), $this->scopes);
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'admin-api:' . $this->generatedBy;
    }
}
