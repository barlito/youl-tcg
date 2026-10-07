<?php

declare(strict_types=1);

namespace App\Service\Admin\Stats;

use App\Enum\Admin\StatsSectionEnum;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.admin_stats_section')]
interface StatsSectionInterface
{
    public function section(): StatsSectionEnum;

    /**
     * @return array<string, mixed> JSON-ready
     */
    public function build(StatsContext $context): array;
}
