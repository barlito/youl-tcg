<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\DiscordUser;
use Symfony\Contracts\Service\ResetInterface;

// Display-only per-request memo of the claims left (header, tab, bell); never use it in a claim flow
final class RemainingClaimsDisplay implements ResetInterface
{
    /** @var array<string, int> */
    private array $memo = [];

    public function __construct(
        private readonly BoosterClaimService $boosterClaimService,
    ) {
    }

    public function forPlayer(DiscordUser $discordUser): int
    {
        // numeric discord ids would become int keys: prefix them
        return $this->memo['u' . $discordUser->getDiscordId()] ??= $this->boosterClaimService->getRemainingClaims($discordUser);
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
