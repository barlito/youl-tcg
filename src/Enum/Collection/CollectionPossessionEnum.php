<?php

declare(strict_types=1);

namespace App\Enum\Collection;

/**
 * « Toutes / Possédées / Manquantes » filter of the collection (url `?filtre=`).
 */
enum CollectionPossessionEnum: string
{
    case ALL = 'toutes';
    case OWNED = 'possedees';
    case MISSING = 'manquantes';
}
