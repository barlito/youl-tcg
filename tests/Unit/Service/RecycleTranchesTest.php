<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Recycle\RecycleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecycleTranchesTest extends TestCase
{
    /**
     * @return iterable<array{int, int, int}> points => [boosters, lost points]
     */
    public static function points(): iterable
    {
        yield [0, 0, 0];
        yield [9, 0, 9];
        yield [10, 1, 0];
        yield [19, 1, 9];
        yield [20, 2, 0];
        yield [23, 2, 3];
        yield [35, 3, 5];
    }

    #[DataProvider('points')]
    public function testEveryFullTrancheIsOneBoosterAndTheRemainderIsLost(int $points, int $boosters, int $lost): void
    {
        $this->assertSame($boosters, RecycleService::boosterCountFor($points));
        $this->assertSame($lost, RecycleService::lostPointsFor($points));
    }
}
