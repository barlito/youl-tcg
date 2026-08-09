<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * State rules of a redemption attempt, in refusal order. Messages are
 * player-facing (French) and rendered as-is by the hub.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RedeemableBoosterCode extends Constraint
{
    /**
     * Unknown and revoked share one message: telling them apart only helps
     * someone probing codes.
     */
    public string $unknownMessage = 'Ce code n\'existe pas ou n\'est plus valide.';

    public string $expiredMessage = 'Ce code a expiré.';

    public string $alreadyRedeemedMessage = 'Tu as déjà utilisé ce code.';

    public string $exhaustedMessage = 'Ce code a déjà été utilisé au maximum.';

    public string $notAvailableYetMessage = 'Ce code est valide, mais son pack n\'est pas encore disponible. Réessaie plus tard !';

    #[\Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
