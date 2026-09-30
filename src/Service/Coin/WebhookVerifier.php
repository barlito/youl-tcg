<?php

declare(strict_types=1);

namespace App\Service\Coin;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(
        #[Autowire(env: 'YTCG_WEBHOOK_SECRET')]
        private string $secret,
        private ClockInterface $clock,
    ) {
    }

    public function isValid(string $body, ?string $timestamp, ?string $signature): bool
    {
        // an empty secret would make every signature forgeable
        if ('' === $this->secret || null === $signature || null === $timestamp || 1 !== preg_match('/^\d+$/', $timestamp)) {
            return false;
        }

        if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret), $signature);
    }
}
