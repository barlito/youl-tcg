<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Principal of the duel game server: a machine, never a player nor an admin.
 */
final readonly class DuelServerUser implements UserInterface
{
    public const string ROLE = 'ROLE_DUEL_SERVER';

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'duel-server';
    }
}
