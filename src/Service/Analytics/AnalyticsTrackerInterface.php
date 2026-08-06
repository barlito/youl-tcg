<?php

declare(strict_types=1);

namespace App\Service\Analytics;

interface AnalyticsTrackerInterface
{
    /**
     * Records an anonymous product event (never any user identifier).
     *
     * Fire-and-forget contract: implementations MUST NOT throw — a broken
     * analytics backend must never break the user flow being tracked.
     *
     * @param array<string, bool|float|int|string> $data
     */
    public function track(string $event, array $data = []): void;
}
