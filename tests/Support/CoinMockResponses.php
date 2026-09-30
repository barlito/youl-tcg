<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for every HTTP call in test. Without an override the coin answers
 * as a healthy one: a 1 500 coins wallet, an accepted payment, no past transaction.
 */
final class CoinMockResponses
{
    public const string BANK_WALLET_ID = '01HAJGPGCP28GFA6QD08NMH764';
    public const string USER_WALLET_ID = '01HAJGPGCP28GFA6QD08NMH765';
    public const string TRANSACTION_ID = 'a1b2c3d4-0000-4000-8000-000000000001';

    /** @var list<array{method: string, path: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, \Closure(): MockResponse> */
    private array $overrides = [];

    /**
     * @param \Closure(): MockResponse $response built per call: a MockResponse is single use
     */
    public function override(string $method, string $path, \Closure $response): void
    {
        $this->overrides[$method . ' ' . $path] = $response;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'options' => $options];

        if (isset($this->overrides[$method . ' ' . $path])) {
            return $this->overrides[$method . ' ' . $path]();
        }

        return match (true) {
            'GET' === $method && '/api/bank/wallet' === $path => self::json(['id' => self::BANK_WALLET_ID, 'type' => 'bank', 'amount' => '999000000000']),
            'GET' === $method && str_ends_with($path, '/wallet') => self::json(['id' => self::USER_WALLET_ID, 'type' => 'user', 'amount' => '150000000000']),
            'POST' === $method && '/api/transactions' === $path => self::json(['id' => self::TRANSACTION_ID], 201),
            'GET' === $method && '/api/transactions' === $path => self::json(['hydra:member' => []]),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type: application/ld+json']]);
    }
}
