<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\DiscordUser;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Display-only, per-request memo of the free claims left: the header badge,
 * the mobile tab and the notification bell share ONE quota query. Never use
 * it in a claim flow — the value is not refreshed after a claim.
 */
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
