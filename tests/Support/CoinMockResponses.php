<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for every HTTP call in test: the Youl Coin wallet endpoint answers
 * with a fixed balance unless a test queues another answer.
 */
final class CoinMockResponses
{
    /** @var list<MockResponse> */
    private array $queue = [];

    public function __invoke(string $method, string $url): ResponseInterface
    {
        return array_shift($this->queue) ?? new MockResponse(json_encode(['amount' => '150000000000']), ['response_headers' => ['content-type: application/ld+json']]);
    }

    public function queue(MockResponse $response): void
    {
        $this->queue[] = $response;
    }
}
