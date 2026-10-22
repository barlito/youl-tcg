<?php

declare(strict_types=1);

namespace App\Enum\Collection;

/**
 * Finish filter of the collection (url `?finition=`): a card matches when the
 * player owns at least one copy in that finish, so it only ever keeps owned cards.
 */
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
