<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\DiscordUser;
use App\Service\Booster\RemainingClaimsDisplay;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * Feeds the header « Boosters » badges with the free claims left today.
 */
final readonly class BoosterClaimExtension
{
    public function __construct(
        private RemainingClaimsDisplay $remainingClaims,
        private Security $security,
    ) {
    }

    #[AsTwigFunction(name: 'remaining_booster_claims')]
    public function remainingBoosterClaims(): int
    {
        $user = $this->security->getUser();

        // the header renders on anonymous error pages too
        if (!$user instanceof DiscordUser) {
            return 0;
        }

        return $this->remainingClaims->forPlayer($user);
    }
}
