<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Principal of the import API: bound to the admin who generated the token, but
 * carries only ROLE_IMPORT_API (never the admin's own roles).
 */
final readonly class ImportApiUser implements UserInterface
{
    public const string ROLE = 'ROLE_IMPORT_API';

    public function __construct(private string $generatedBy)
    {
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'import-api:' . $this->generatedBy;
    }
}
