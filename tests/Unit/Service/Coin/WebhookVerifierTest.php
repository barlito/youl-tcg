<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Coin;

use App\Service\Coin\WebhookVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WebhookVerifierTest extends TestCase
{
    private const string NOW = '2026-09-30 12:00:00';

    public function testAcceptsAValidSignature(): void
    {
        $this->assertTrue($this->verifier('s3cret')->isValid('{"a":1}', (string) $this->now(), $this->sign('{"a":1}', $this->now(), 's3cret')));
    }

    public function testRefusesAnotherSecretOrBody(): void
    {
        $verifier = $this->verifier('s3cret');

        $this->assertFalse($verifier->isValid('{"a":1}', (string) $this->now(), $this->sign('{"a":1}', $this->now(), 'other')));
        $this->assertFalse($verifier->isValid('{"a":2}', (string) $this->now(), $this->sign('{"a":1}', $this->now(), 's3cret')));
    }

    public function testRefusesAStaleOrFutureTimestamp(): void
    {
        $verifier = $this->verifier('s3cret');

        foreach ([-301, 301] as $shift) {
            $timestamp = $this->now() + $shift;
            $this->assertFalse($verifier->isValid('{}', (string) $timestamp, $this->sign('{}', $timestamp, 's3cret')));
        }

        $timestamp = $this->now() - 299;
        $this->assertTrue($verifier->isValid('{}', (string) $timestamp, $this->sign('{}', $timestamp, 's3cret')));
    }

    public function testRefusesMissingHeaders(): void
    {
        $verifier = $this->verifier('s3cret');

        $this->assertFalse($verifier->isValid('{}', null, 'sha256=x'));
        $this->assertFalse($verifier->isValid('{}', (string) $this->now(), null));
        $this->assertFalse($verifier->isValid('{}', 'abc', 'sha256=x'));
    }

    public function testAnEmptySecretRefusesEverything(): void
    {
        $this->assertFalse($this->verifier('')->isValid('{}', (string) $this->now(), $this->sign('{}', $this->now(), '')));
    }

    private function verifier(string $secret): WebhookVerifier
    {
        return new WebhookVerifier($secret, new MockClock(self::NOW, 'UTC'));
    }

    private function now(): int
    {
        return (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private function sign(string $body, int $timestamp, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}
