<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\Admin\AdminApiScopeEnum;

final readonly class AdminApiTokenInfo
{
    /**
     * @param list<AdminApiScopeEnum> $scopes
     */
    public function __construct(
        public string $generatedBy,
        public \DateTimeImmutable $expiresAt,
        public array $scopes,
    ) {
    }

    public function hasScope(AdminApiScopeEnum $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }
}
