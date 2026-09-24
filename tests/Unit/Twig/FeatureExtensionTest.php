<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Repository\FeatureFlagRepository;
use App\Service\Feature\FeatureFlags;
use App\Twig\FeatureExtension;
use PHPUnit\Framework\TestCase;

final class FeatureExtensionTest extends TestCase
{
    public function testItReadsTheFlag(): void
    {
        $extension = $this->extension(['recycling' => true]);

        $this->assertTrue($extension->featureEnabled('recycling'));
        $this->assertFalse($extension->featureEnabled('trades'));
    }

    public function testAnUnknownNameIsAnError(): void
    {
        $this->expectException(\ValueError::class);

        $this->extension([])->featureEnabled('recyling');
    }

    /**
     * @param array<string, bool> $states
     */
    private function extension(array $states): FeatureExtension
    {
        $repository = $this->createStub(FeatureFlagRepository::class);
        $repository->method('findStates')->willReturn($states);

        return new FeatureExtension(new FeatureFlags($repository));
    }
}
