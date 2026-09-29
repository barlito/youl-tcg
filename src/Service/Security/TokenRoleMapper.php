<?php

declare(strict_types=1);

namespace App\Service\Security;

final readonly class TokenRoleMapper
{
    private const string TOKEN_PREFIX = 'ROLE_YTCG_';

    private const string APP_PREFIX = 'ROLE_';

    /**
     * Keeps only this app's roles (ROLE_YTCG_ADMIN => ROLE_ADMIN); any other role of the token is ignored.
     *
     * @param list<string> $tokenRoles
     *
     * @return list<string>
     */
    public function fromToken(array $tokenRoles): array
    {
        $appRoles = [];
        foreach ($tokenRoles as $role) {
            if (str_starts_with($role, self::TOKEN_PREFIX) && \strlen($role) > \strlen(self::TOKEN_PREFIX)) {
                $appRoles[] = self::APP_PREFIX . substr($role, \strlen(self::TOKEN_PREFIX));
            }
        }

        return array_values(array_unique($appRoles));
    }

    /**
     * @param list<string> $appRoles
     *
     * @return list<string>
     */
    public function toToken(array $appRoles): array
    {
        $tokenRoles = [];
        foreach ($appRoles as $role) {
            if ('ROLE_USER' !== $role && str_starts_with($role, self::APP_PREFIX)) {
                $tokenRoles[] = self::TOKEN_PREFIX . substr($role, \strlen(self::APP_PREFIX));
            }
        }

        return array_values(array_unique($tokenRoles));
    }
}
