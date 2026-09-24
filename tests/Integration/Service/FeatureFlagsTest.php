<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\FeatureFlag;
use App\Enum\FeatureEnum;
use App\Service\Feature\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeatureFlagsTest extends KernelTestCase
{
    public function testTheFixturesSwitchEveryFeatureOn(): void
    {
        $flags = self::getContainer()->get(FeatureFlags::class);

        foreach (FeatureEnum::cases() as $feature) {
            $this->assertTrue($flags->isEnabled($feature), $feature->value);
        }
    }

    public function testADeletedRowMeansOff(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $flag = $entityManager->find(FeatureFlag::class, FeatureEnum::TRADES->value);
        $this->assertInstanceOf(FeatureFlag::class, $flag);
        $entityManager->remove($flag);
        $entityManager->flush();

        $flags = self::getContainer()->get(FeatureFlags::class);
        $flags->reset();

        $this->assertFalse($flags->isEnabled(FeatureEnum::TRADES));
        $this->assertTrue($flags->isEnabled(FeatureEnum::RECYCLING));
    }
}
