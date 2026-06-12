<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Random\RandomService;
use PHPUnit\Framework\TestCase;

final class RandomServiceTest extends TestCase
{
    public function testSameSeedProducesSameSequence(): void
    {
        $first = new RandomService();
        $first->seed(42);
        $second = new RandomService();
        $second->seed(42);

        $firstSequence = $this->drawSequence($first);

        $this->assertSame($firstSequence, $this->drawSequence($second));
    }

    public function testDifferentSeedsProduceDifferentSequences(): void
    {
        $first = new RandomService();
        $first->seed(42);
        $second = new RandomService();
        $second->seed(43);

        $this->assertNotSame($this->drawSequence($first), $this->drawSequence($second));
    }

    public function testReseedingRestartsTheSequence(): void
    {
        $random = new RandomService();
        $random->seed(42);
        $sequence = $this->drawSequence($random);

        $random->seed(42);

        $this->assertSame($sequence, $this->drawSequence($random));
    }

    public function testGetSeedExposesTheLastSeed(): void
    {
        $random = new RandomService();

        $this->assertNull($random->getSeed());

        $random->seed(1337);

        $this->assertSame(1337, $random->getSeed());
    }

    public function testWorksUnseeded(): void
    {
        $value = new RandomService()->getInt(1, 10);

        $this->assertGreaterThanOrEqual(1, $value);
        $this->assertLessThanOrEqual(10, $value);
    }

    /**
     * @return list<int>
     */
    private function drawSequence(RandomService $random): array
    {
        return array_map(static fn (): int => $random->getInt(1, 1_000_000), range(1, 20));
    }
}
