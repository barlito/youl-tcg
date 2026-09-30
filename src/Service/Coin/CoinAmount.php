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
