<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Coin;

use App\Service\Coin\CoinAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoinAmountTest extends TestCase
{
    #[DataProvider('formats')]
    public function testFormatsMinorUnitsInFrench(string $minor, string $expected): void
    {
        $this->assertSame($expected, CoinAmount::fromMinor($minor)->format());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formats(): iterable
    {
        yield 'zero' => ['0', '0'];
        yield 'leading zeros' => ['000', '0'];
        yield 'whole coins' => ['100000000000', "1\u{202F}000"];
        yield 'millions' => ['123456789000000000', "1\u{202F}234\u{202F}567\u{202F}890"];
        yield 'fraction trimmed' => ['150000000', '1,5'];
        yield 'smallest unit' => ['1', '0,00000001'];
        yield 'negative' => ['-250000000', '-2,5'];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnythingButMinorUnits(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CoinAmount::fromMinor($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'decimal' => ['1.5'];
        yield 'text' => ['abc'];
    }
}
