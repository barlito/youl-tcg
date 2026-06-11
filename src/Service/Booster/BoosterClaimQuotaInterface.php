<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\DiscordUser;

/**
 * Quota policy for daily free booster claims.
 */
interface BoosterClaimQuotaInterface
{
    public const int DAILY_LIMIT = 2;

    public function getRemainingClaims(DiscordUser $discordUser): int;

    public function getNextResetTime(): \DateTimeImmutable;
}
