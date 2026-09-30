<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Coin;

use App\Service\Coin\YoulCoinClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\ScopingHttpClient;

final class YoulCoinClientTest extends TestCase
{
    public function testReadsTheBalanceWithTheBearerKey(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['normalized_headers']['authorization'][0] ?? null];

            return new MockResponse('{"amount":"150000000"}');
        });

        $this->assertSame('1,5', $client->getBalance('123')?->format());
        $this->assertSame(['GET', 'http://coin/api/user/123/wallet', 'Authorization: Bearer secret-key'], $seen);
    }

    public function testMissingWalletIsAZeroBalance(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 404]));

        $this->assertSame('0', $client->getBalance('123')?->format());
    }

    public function testServerErrorMeansUnavailable(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        $this->assertNull($client->getBalance('123'));
    }

    public function testTransportFailureMeansUnavailable(): void
    {
        $client = $this->client(static function (): never {
            throw new TransportException('timeout');
        });

        $this->assertNull($client->getBalance('123'));
    }

    public function testMalformedAnswerMeansUnavailable(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('{"foo":1}'));

        $this->assertNull($client->getBalance('123'));
    }

    private function client(callable $responses): YoulCoinClient
    {
        return new YoulCoinClient(
            ScopingHttpClient::forBaseUri(new MockHttpClient($responses), 'http://coin', ['auth_bearer' => 'secret-key']),
            new NullLogger(),
        );
    }
}
