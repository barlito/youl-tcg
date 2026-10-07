<?php

declare(strict_types=1);

namespace App\Enum\Admin;

enum AdminApiScopeEnum: string
{
    case IMPORT = 'import';
    case STATS = 'stats';
    case MANAGE = 'manage';

    public function role(): string
    {
        return match ($this) {
            self::IMPORT => 'ROLE_IMPORT_API',
            self::STATS => 'ROLE_STATS_API',
            self::MANAGE => 'ROLE_MANAGE_API',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::IMPORT => 'Import',
            self::STATS => 'Stats',
            self::MANAGE => 'Gestion',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::IMPORT => 'création et lecture d\'extensions et de cartes (brouillons uniquement)',
            self::STATS => 'lecture seule de toutes les données du jeu, joueurs inclus',
            self::MANAGE => 'modifier/publier extensions, cartes, boosters, réglages (jamais de suppression)',
        };
    }

    /**
     * Pre-ticked on the token form; the write scope is opt-in.
     */
    public function checkedByDefault(): bool
    {
        return self::MANAGE !== $this;
    }

    /**
     * @param iterable<mixed> $values
     *
     * @return list<self>
     */
    public static function fromValues(iterable $values): array
    {
        $scopes = [];

        foreach ($values as $value) {
            $scope = \is_string($value) ? self::tryFrom($value) : null;

            if ($scope instanceof self && !\in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }
}
