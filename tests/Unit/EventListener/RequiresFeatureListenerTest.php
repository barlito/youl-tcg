<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\RequiresFeatureListener;
use App\Exception\Feature\FeatureDisabledException;
use App\Repository\FeatureFlagRepository;
use App\Service\Feature\FeatureFlags;
use App\Tests\Unit\EventListener\Fixtures\PlainController;
use App\Tests\Unit\EventListener\Fixtures\RecyclingController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequiresFeatureListenerTest extends TestCase
{
    public function testAControllerWithoutAttributePasses(): void
    {
        $this->dispatch([new PlainController(), 'index'], []);

        $this->addToAssertionCount(1);
    }

    public function testAClassAttributeBlocksEveryMethodWhileOff(): void
    {
        $this->expectException(FeatureDisabledException::class);

        $this->dispatch([new RecyclingController(), 'index'], ['recycling' => false]);
    }

    public function testAClassAttributePassesWhileOn(): void
    {
        $this->dispatch([new RecyclingController(), 'index'], ['recycling' => true]);

        $this->addToAssertionCount(1);
    }

    public function testAMethodAttributeOnlyGuardsThatMethod(): void
    {
        $this->dispatch([new PlainController(), 'index'], ['trades' => false]);

        $this->expectException(FeatureDisabledException::class);
        $this->dispatch([new PlainController(), 'trades'], ['trades' => false]);
    }

    public function testEveryRepeatedAttributeMustBeOn(): void
    {
        $this->expectException(FeatureDisabledException::class);

        // class needs recycling (on), the method also needs trades (off)
        $this->dispatch([new RecyclingController(), 'both'], ['recycling' => true, 'trades' => false]);
    }

    public function testSubRequestsAreGuardedToo(): void
    {
        $this->expectException(FeatureDisabledException::class);

        $this->dispatch([new RecyclingController(), 'index'], [], HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * @param array<string, bool> $states
     */
    private function dispatch(callable $controller, array $states, int $requestType = HttpKernelInterface::MAIN_REQUEST): void
    {
        $repository = $this->createStub(FeatureFlagRepository::class);
        $repository->method('findStates')->willReturn($states);

        $event = new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), $controller, [], new Request(), $requestType);
        new RequiresFeatureListener(new FeatureFlags($repository))($event);
    }
}
