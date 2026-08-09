<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\BoosterCode;
use App\Entity\DiscordUser;
use App\Validator\RedeemableBoosterCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a player submits, plus the code row it resolves to. The group sequence
 * runs the shape rules first (own class group) and only reaches the state
 * rules — RedeemableBoosterCode, in the "state" group — once there is
 * something to look at.
 *
 * $boosterCode is resolved by the caller INSIDE the redemption transaction,
 * under a write lock: the rules below (uses vs maxUses, one redemption per
 * player) are only true as long as that lock is held.
 */
#[Assert\GroupSequence(['BoosterCodeRedemptionAttempt', 'state'])]
#[RedeemableBoosterCode(groups: ['state'])]
final readonly class BoosterCodeRedemptionAttempt
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisis un code pour continuer.')]
        public string $code,
        public DiscordUser $discordUser,
        public ?BoosterCode $boosterCode,
    ) {
    }
}
