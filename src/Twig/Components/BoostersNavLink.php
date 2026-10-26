<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

// « Boosters » entry (header or mobile tab) with its free-claims badge, re-rendered on `inventory-changed`
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
