<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\FeatureEnum;
use App\Exception\Feature\FeatureDisabledException;
use App\Repository\FeatureFlagRepository;
use App\Service\Feature\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FeatureFlagsTest extends TestCase
{
    public function testAMissingRowIsOff(): void
    {
        $flags = new FeatureFlags($this->repository(['recycling' => true]));

        $this->assertTrue($flags->isEnabled(FeatureEnum::RECYCLING));
        $this->assertFalse($flags->isEnabled(FeatureEnum::TRADES));
    }

    public function testTheTableIsReadOncePerRequest(): void
    {
        $repository = $this->createMock(FeatureFlagRepository::class);
        $repository->expects($this->once())->method('findStates')->willReturn(['trades' => false, 'recycling' => true]);
        $flags = new FeatureFlags($repository);

        $flags->isEnabled(FeatureEnum::TRADES);
        $flags->isEnabled(FeatureEnum::RECYCLING);
        $flags->assertEnabled(FeatureEnum::RECYCLING);
    }

    public function testResetReadsTheTableAgain(): void
    {
        $repository = $this->createMock(FeatureFlagRepository::class);
        $repository->expects($this->exactly(2))->method('findStates')->willReturn(['trades' => false], ['trades' => true]);
        $flags = new FeatureFlags($repository);

        $this->assertFalse($flags->isEnabled(FeatureEnum::TRADES));
        $flags->reset();
        $this->assertTrue($flags->isEnabled(FeatureEnum::TRADES));
    }

    public function testAssertEnabledThrowsANotFoundForADisabledFeature(): void
    {
        $flags = new FeatureFlags($this->repository(['trades' => false]));

        try {
            $flags->assertEnabled(FeatureEnum::TRADES);
            $this->fail('A disabled feature must throw.');
        } catch (FeatureDisabledException $exception) {
            $this->assertInstanceOf(NotFoundHttpException::class, $exception);
            $this->assertSame(FeatureEnum::TRADES, $exception->feature);
        }
    }

    /**
     * @param array<string, bool> $states
     */
    private function repository(array $states): FeatureFlagRepository
    {
        $repository = $this->createStub(FeatureFlagRepository::class);
        $repository->method('findStates')->willReturn($states);

        return $repository;
    }
}
