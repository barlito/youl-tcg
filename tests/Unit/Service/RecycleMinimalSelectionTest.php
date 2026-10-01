<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Recycle\RecycleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecycleMinimalSelectionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, bool, int}> points, cheapest copy => overshooting, lost points
     */
    public static function cases(): iterable
    {
        yield 'exactly 10 of commons' => [10, 1, false, 0];
        yield '14 with a legendary last, cheapest is the legendary' => [14, 5, false, 4];
        yield '14 with commons still inside' => [14, 1, true, 4];
        yield '20 commons' => [20, 1, true, 10];
        yield '11 = 1 + 10 commons' => [11, 1, true, 1];
        yield 'below the cost' => [9, 1, false, 0];
        yield '12 of holo commons (2 each)' => [12, 2, true, 2];
    }

    #[DataProvider('cases')]
    public function testOvershootAndLostPoints(int $points, int $cheapest, bool $overshooting, int $lost): void
    {
        $this->assertSame($overshooting, RecycleService::isOvershooting($points, $cheapest));
        $this->assertSame($lost, RecycleService::lostPointsFor($points));
    }
}
