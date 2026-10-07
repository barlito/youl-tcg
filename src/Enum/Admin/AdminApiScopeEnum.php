<?php

declare(strict_types=1);

namespace App\Enum\Admin;

enum AdminApiScopeEnum: string
{
    case IMPORT = 'import';
    case STATS = 'stats';

    public function role(): string
    {
        return match ($this) {
            self::IMPORT => 'ROLE_IMPORT_API',
            self::STATS => 'ROLE_STATS_API',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::IMPORT => 'Import',
            self::STATS => 'Stats',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::IMPORT => 'création et lecture d\'extensions et de cartes (brouillons uniquement)',
            self::STATS => 'lecture seule de toutes les données du jeu, joueurs inclus',
        };
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
