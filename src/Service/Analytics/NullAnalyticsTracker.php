<?php

declare(strict_types=1);

namespace App\Service\Analytics;

/**
 * Safe default (test, CI, any env without an Umami instance): events are dropped.
 */
final class NullAnalyticsTracker implements AnalyticsTrackerInterface
{
    public function track(string $event, array $data = []): void
    {
    }
}
