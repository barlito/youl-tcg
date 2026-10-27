<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Header « Boosters » entry (desktop nav or mobile tab) and its badge of free
 * claims left today (remaining_booster_claims()). Re-renders on the realtime
 * `inventory-changed` event, which every claim publishes.
 */
#[AsLiveComponent]
final class BoostersNavLink
{
    use DefaultActionTrait;

    /** Frozen at mount: a re-render request no longer carries the page route. */
    #[LiveProp]
    public bool $active = false;

    /** 'nav' (desktop header) or 'tab' (mobile tab bar). */
    #[LiveProp]
    public string $variant = 'nav';
}
