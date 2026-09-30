<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Coin;

use App\Enum\Coin\CoinPaymentStatusEnum;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\YoulCoinClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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

    public function testDebitSendsThePurchaseToTheBankWithThePlayerToken(): void
    {
        $sent = [];
        $client = $this->client(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            if ('POST' === $method) {
                $sent = [$url, json_decode($options['body'], true), $options['normalized_headers']['x-player-token'][0] ?? null];

                return new MockResponse('{"id":"tx-1"}', ['http_code' => 201]);
            }

            return new MockResponse(str_contains($url, '/bank/') ? '{"id":"BANK"}' : '{"id":"USER"}');
        });

        $payment = $client->debitToBank('123', CoinAmount::fromCoins(10), 'ytcg:booster-purchase:abc', 'player-jwt');

        $this->assertSame(CoinPaymentStatusEnum::PAID, $payment->status);
        $this->assertSame('tx-1', $payment->transactionId);
        $this->assertSame(
            ['http://coin/api/transactions', ['amount' => '1000000000', 'walletFrom' => '/api/wallets/USER', 'walletTo' => '/api/wallets/BANK', 'type' => 'purchase', 'externalIdentifier' => 'ytcg:booster-purchase:abc'], 'X-Player-Token: player-jwt'],
            $sent,
        );
    }

    public function testTheBankWalletIdIsCached(): void
    {
        $bankReads = 0;
        $client = $this->client(static function (string $method, string $url) use (&$bankReads): MockResponse {
            $bankReads += str_contains($url, '/bank/') ? 1 : 0;

            return 'POST' === $method ? new MockResponse('{"id":"tx"}', ['http_code' => 201]) : new MockResponse('{"id":"W"}');
        });

        $client->debitToBank('1', CoinAmount::fromCoins(1), 'a', 't');
        $client->debitToBank('1', CoinAmount::fromCoins(1), 'b', 't');

        $this->assertSame(1, $bankReads);
    }

    /**
     * @return iterable<string, array{int, CoinPaymentStatusEnum}>
     */
    public static function debitAnswers(): iterable
    {
        yield 'insufficient funds' => [422, CoinPaymentStatusEnum::REFUSED];
        yield 'wrong token' => [403, CoinPaymentStatusEnum::REFUSED];
        yield 'idempotency conflict' => [409, CoinPaymentStatusEnum::REFUSED];
        yield 'server error' => [500, CoinPaymentStatusEnum::UNCERTAIN];
        yield 'bad gateway' => [502, CoinPaymentStatusEnum::UNCERTAIN];
    }

    #[DataProvider('debitAnswers')]
    public function testDebitTellsAFinalRefusalFromAnUncertainOutcome(int $status, CoinPaymentStatusEnum $expected): void
    {
        $client = $this->client(static fn (string $method): MockResponse => 'POST' === $method ? new MockResponse('{}', ['http_code' => $status]) : new MockResponse('{"id":"W"}'));

        $payment = $client->debitToBank('1', CoinAmount::fromCoins(1), 'a', 't');

        $this->assertSame($expected, $payment->status);
    }

    public function testDebitTimeoutIsUncertain(): void
    {
        $client = $this->client(static fn (string $method): MockResponse => 'POST' === $method ? new MockResponse('', ['error' => 'timeout']) : new MockResponse('{"id":"W"}'));

        $this->assertSame(CoinPaymentStatusEnum::UNCERTAIN, $client->debitToBank('1', CoinAmount::fromCoins(1), 'a', 't')->status);
    }

    public function testDebitWithoutReachableWalletsSendsNothing(): void
    {
        $posted = false;
        $client = $this->client(static function (string $method) use (&$posted): MockResponse {
            $posted = $posted || 'POST' === $method;

            return new MockResponse('', ['http_code' => 404]);
        });

        $this->assertSame(CoinPaymentStatusEnum::UNAVAILABLE, $client->debitToBank('1', CoinAmount::fromCoins(1), 'a', 't')->status);
        $this->assertFalse($posted);
    }

    public function testFindTransactionDistinguishesFoundMissingAndUnreachable(): void
    {
        $found = $this->client(static fn (): MockResponse => new MockResponse('{"hydra:member":[{"id":"tx-9"}]}'));
        $missing = $this->client(static fn (): MockResponse => new MockResponse('{"hydra:member":[]}'));
        $down = $this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        $this->assertSame('tx-9', $found->findTransaction('x')->transactionId);
        $this->assertSame(CoinPaymentStatusEnum::NOT_FOUND, $missing->findTransaction('x')->status);
        $this->assertSame(CoinPaymentStatusEnum::UNAVAILABLE, $down->findTransaction('x')->status);
    }

    private function client(callable $responses): YoulCoinClient
    {
        return new YoulCoinClient(
            ScopingHttpClient::forBaseUri(new MockHttpClient($responses), 'http://coin', ['auth_bearer' => 'secret-key']),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }
}
