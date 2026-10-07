<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin\Stats;

use App\Service\Admin\Stats\StatsMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatsMathTest extends TestCase
{
    /**
     * @param list<int|float> $values
     */
    #[DataProvider('medians')]
    public function testMedian(array $values, ?float $expected): void
    {
        $this->assertSame($expected, StatsMath::median($values));
    }

    /**
     * @return iterable<string, array{list<int|float>, float|null}>
     */
    public static function medians(): iterable
    {
        yield 'empty' => [[], null];
        yield 'single' => [[7], 7.0];
        yield 'odd count, unsorted' => [[9, 1, 5], 5.0];
        yield 'even count averages the middle pair' => [[10, 40, 20, 30], 25.0];
        yield 'duplicates' => [[5, 5, 5, 100], 5.0];
        yield 'floats' => [[1.5, 2.5], 2.0];
    }

    public function testAverageIsRoundedAndNullWhenEmpty(): void
    {
        $this->assertNull(StatsMath::average([]));
        $this->assertSame(3.33, StatsMath::average([1, 2, 7]));
    }

    public function testSummary(): void
    {
        $this->assertSame(['count' => 4, 'min' => 1, 'median' => 2.5, 'max' => 9, 'average' => 3.75], StatsMath::summary([1, 2, 3, 9]));
        $this->assertSame(['count' => 0, 'min' => null, 'median' => null, 'max' => null, 'average' => null], StatsMath::summary([]));
    }
}
