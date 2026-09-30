<?php

declare(strict_types=1);

namespace App\Service\Coin;

use App\Enum\Coin\CoinPaymentStatusEnum;

final readonly class CoinPayment
{
    private function __construct(
        public CoinPaymentStatusEnum $status,
        public ?string $transactionId = null,
        public ?int $httpStatus = null,
    ) {
    }

    public static function paid(string $transactionId): self
    {
        return new self(CoinPaymentStatusEnum::PAID, $transactionId);
    }

    public static function refused(int $httpStatus): self
    {
        return new self(CoinPaymentStatusEnum::REFUSED, httpStatus: $httpStatus);
    }

    public static function unavailable(): self
    {
        return new self(CoinPaymentStatusEnum::UNAVAILABLE);
    }

    public static function uncertain(): self
    {
        return new self(CoinPaymentStatusEnum::UNCERTAIN);
    }

    public static function notFound(): self
    {
        return new self(CoinPaymentStatusEnum::NOT_FOUND);
    }
}
