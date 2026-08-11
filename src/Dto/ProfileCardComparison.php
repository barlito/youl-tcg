<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Card;
use App\Entity\UserCard;
use App\Enum\ProfileCardStateEnum;

/**
 * One catalogue card seen through the profile/visitor comparison. profileCard
 * is only carried when the state reveals the card, so a masked tile has no
 * quantity to leak.
 */
final readonly class ProfileCardComparison
{
    public function __construct(
        public Card $card,
        public ProfileCardStateEnum $state,
        public ?UserCard $profileCard = null,
    ) {
    }
}
