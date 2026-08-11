<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How one catalogue card compares between the visited profile and the visitor.
 * Drives both the tile rendering and the client-side filter (the value is the
 * data-state attribute).
 */
enum ProfileCardStateEnum: string
{
    /** both own it — the only case where the profile's quantities are shown */
    case COMMON = 'common';

    /** the profile owns it, the visitor doesn't: masked, as it has always been */
    case PROFILE_ONLY = 'profile-only';

    /** the visitor owns it, the profile doesn't: nothing of the profile to hide */
    case VISITOR_ONLY = 'visitor-only';

    /** nobody owns it — the shared hunt */
    case MISSING_BOTH = 'missing-both';

    /** a 1/1 the visitor doesn't hold: never says whether the profile has it */
    case MYSTERY = 'mystery';

    /**
     * Whether the artwork (and therefore the card name) may be rendered.
     */
    public function revealsCard(): bool
    {
        return self::COMMON === $this || self::VISITOR_ONLY === $this;
    }

    /**
     * States kept out of the comparison counters: a 1/1 must stay a count, so
     * a universe holding a single unique cannot be solved by subtraction.
     */
    public function isCountable(): bool
    {
        return self::MYSTERY !== $this;
    }
}
