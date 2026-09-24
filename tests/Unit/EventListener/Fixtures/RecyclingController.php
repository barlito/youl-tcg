<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener\Fixtures;

use App\Attribute\RequiresFeature;
use App\Enum\FeatureEnum;

#[RequiresFeature(FeatureEnum::RECYCLING)]
final class RecyclingController
{
    public function index(): void
    {
    }

    #[RequiresFeature(FeatureEnum::TRADES)]
    public function both(): void
    {
    }
}
