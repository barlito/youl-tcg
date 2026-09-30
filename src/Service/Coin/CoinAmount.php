<?php

declare(strict_types=1);

namespace App\Service\Coin;

final readonly class CoinAmount
{
    public const int SCALE = 8;

    private function __construct(
        public string $minor,
    ) {
    }

    public static function fromMinor(string $minor): self
    {
        if (1 !== preg_match('/^-?\d+$/', $minor)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an amount in minor units.', $minor));
        }

        return new self('' === ltrim($minor, '0') ? '0' : $minor);
    }

    public static function fromCoins(int $coins): self
    {
        return self::fromMinor($coins . str_repeat('0', self::SCALE));
    }

    public function isLessThan(self $other): bool
    {
        $negative = str_starts_with($this->minor, '-');

        if ($negative !== str_starts_with($other->minor, '-')) {
            return $negative;
        }

        $length = \strlen($this->minor) <=> \strlen($other->minor);
        $comparison = 0 !== $length ? $length : strcmp($this->minor, $other->minor);

        return ($negative ? -$comparison : $comparison) < 0;
    }

    /** French display: thin space thousands separator, decimal comma, no useless decimals. */
    public function format(): string
    {
        $negative = str_starts_with($this->minor, '-');
        $digits = str_pad(ltrim($this->minor, '-'), self::SCALE + 1, '0', \STR_PAD_LEFT);

        $integer = substr($digits, 0, -self::SCALE);
        $fraction = rtrim(substr($digits, -self::SCALE), '0');

        $grouped = preg_replace('/\B(?=(\d{3})+$)/', "\u{202F}", $integer);

        return ($negative ? '-' : '') . $grouped . ('' === $fraction ? '' : ',' . $fraction);
    }
}
