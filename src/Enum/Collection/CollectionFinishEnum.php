<?php

declare(strict_types=1);

namespace App\Enum\Collection;

// Collection `?finition=` filter: owns at least one copy in that finish (owned cards only)
enum CollectionFinishEnum: string
{
    case HOLO = 'holo';
    case NORMAL = 'normale';

    public function label(): string
    {
        return match ($this) {
            self::HOLO => 'Holo',
            self::NORMAL => 'Normale',
        };
    }
}
