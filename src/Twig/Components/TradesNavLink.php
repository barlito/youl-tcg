<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Header "Échanges" entry and its pending-offers badge (pending_trade_offers(),
 * 0 when trades are off or nobody is logged in). Re-renders on the realtime
 * `trades-changed` event and on the local `trades:changed` echo of the inbox.
 */
#[AsLiveComponent]
final class TradesNavLink
{
    use DefaultActionTrait;

    /** Frozen at mount: a re-render request no longer carries the page route. */
    #[LiveProp]
    public bool $active = false;
}
