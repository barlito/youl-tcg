<?php

declare(strict_types=1);

namespace App\Service\Duel;

use App\Entity\UserCard;

/**
 * Which copy a deck card plays, given what the owner holds: the player only chooses when owning both kinds.
 */
final class DeckOwnership
{
    /**
     * Holo when only holo copies are owned, normal when none is, the stored choice when both kinds are owned.
     */
    public static function playsHolo(?UserCard $row, bool $chosenHolo): bool
    {
        if (!$row instanceof UserCard || $row->getHoloQuantity() <= 0) {
            return false;
        }

        return $row->getNormalQuantity() <= 0 || $chosenHolo;
    }

    public static function ownsAny(?UserCard $row): bool
    {
        return $row instanceof UserCard && max($row->getQuantity(), $row->getHoloQuantity()) > 0;
    }
}
