<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\FeatureFlag;
use App\Enum\FeatureEnum;
use App\Service\Feature\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The test fixtures switch every feature ON: "OFF" scenarios flip it here.
 */
trait FeatureFlagTrait
{
    private function setFeature(FeatureEnum $feature, bool $enabled): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $flag = $entityManager->find(FeatureFlag::class, $feature->value) ?? new FeatureFlag($feature);
        $entityManager->persist($flag->setEnabled($enabled));
        $entityManager->flush();

        static::getContainer()->get(FeatureFlags::class)->reset();
    }
}
