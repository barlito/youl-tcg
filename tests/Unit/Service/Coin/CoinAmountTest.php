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

    public function testFromCoinsScalesToMinorUnits(): void
    {
        $this->assertSame('1000000000', CoinAmount::fromCoins(10)->minor);
        $this->assertSame('10', CoinAmount::fromCoins(10)->format());
    }

    #[DataProvider('comparisons')]
    public function testIsLessThan(string $a, string $b, bool $expected): void
    {
        $this->assertSame($expected, CoinAmount::fromMinor($a)->isLessThan(CoinAmount::fromMinor($b)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'smaller' => ['999999999', '1000000000', true];
        yield 'equal' => ['1000000000', '1000000000', false];
        yield 'greater' => ['1000000001', '1000000000', false];
        yield 'zero vs positive' => ['0', '1', true];
        yield 'negative vs positive' => ['-5', '1', true];
        yield 'positive vs negative' => ['1', '-5', false];
        yield 'two negatives' => ['-10', '-5', true];
    }
}
