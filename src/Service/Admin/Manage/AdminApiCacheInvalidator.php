<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Enum\Admin\EconomyPeriodEnum;
use App\Service\Admin\Stats\ApiStatsProvider;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Drops the cached read models a management write can make stale (stats API, economy dashboard, homepage cards).
 */
final readonly class AdminApiCacheInvalidator
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function afterWrite(): void
    {
        $this->cache->delete(ApiStatsProvider::VERSION_KEY);
        $this->cache->delete('daycards');
        foreach (EconomyPeriodEnum::cases() as $period) {
            $this->cache->delete('admin_economy_dashboard_' . $period->value);
        }
    }
}
