<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Coin\WalletBalances;
use App\Tests\Support\CoinMockResponses;
use App\Tests\Support\SpyHub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YoulCoinWalletTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string JUJU = '195659530363731968';
    private const string SECRET = 'test-webhook-secret';

    private function createCleanClient(): KernelBrowser
    {
        $client = static::createClient();
        static::getContainer()->get('cache.app')->clear();

        return $client;
    }

    public function testTheHeaderShowsTheBalance(): void
    {
        $client = $this->createCleanClient();
        $this->authenticateClient($client, self::JUJU);

        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSame('1 500 YLC', preg_replace('/\s+/u', ' ', trim($crawler->filter('[data-testid="wallet-balance"]')->text())));
        $this->assertSame('https://yc.youlz.fr', $crawler->filter('[data-testid="wallet-balance"]')->attr('href'));
        $this->assertNull($crawler->filter('[data-testid="wallet-balance"]')->attr('title'));
    }

    public function testAnUnavailableCoinNeverBreaksThePage(): void
    {
        $client = $this->createCleanClient();
        $this->authenticateClient($client, self::JUJU);
        static::getContainer()->get(CoinMockResponses::class)->queue(new MockResponse('', ['http_code' => 503]));

        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $chip = $crawler->filter('[data-testid="wallet-balance"]');
        $this->assertStringContainsString('— YLC', preg_replace('/\s+/', ' ', $chip->text()));
        $this->assertStringContainsString('indisponible', (string) $chip->attr('title'));
        $this->assertCount(1, $crawler->filter('[data-testid="live-updates"]'));
    }

    public function testTheBalanceIsCachedBetweenPages(): void
    {
        $client = $this->createCleanClient();
        $this->authenticateClient($client, self::JUJU);
        $client->request('GET', '/');
        static::getContainer()->get(CoinMockResponses::class)->queue(new MockResponse('', ['http_code' => 503]));

        $crawler = $client->request('GET', '/');

        $this->assertStringNotContainsString('—', $crawler->filter('[data-testid="wallet-balance"]')->text());
    }

    public function testAValidWebhookUpdatesTheCacheAndNotifiesThePlayer(): void
    {
        $client = $this->createCleanClient();
        $body = $this->payload([['discordId' => self::JUJU, 'balance' => '250000000'], ['discordId' => '999', 'balance' => '1']]);

        $this->post($client, $body);

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('2,5', static::getContainer()->get(WalletBalances::class)->get(self::JUJU)?->format());
        $events = static::getContainer()->get(SpyHub::class)->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('wallet-changed', $events[0]['type']);
        $this->assertSame(['balance' => '250000000', 'formatted' => '2,5'], $events[0]['payload']);
    }

    public function testAnUnknownPlayerIsIgnored(): void
    {
        $client = $this->createCleanClient();

        $this->post($client, $this->payload([['discordId' => '999', 'balance' => '1']]));

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame([], static::getContainer()->get(SpyHub::class)->getEvents());
    }

    public function testABadSignatureOrStaleTimestampIsRefused(): void
    {
        $client = $this->createCleanClient();
        $body = $this->payload([['discordId' => self::JUJU, 'balance' => '1']]);

        $this->post($client, $body, signature: 'sha256=deadbeef');
        $this->assertResponseStatusCodeSame(401);

        $this->post($client, $body, timestamp: time() - 600);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame([], static::getContainer()->get(SpyHub::class)->getEvents());
    }

    public function testAnInvalidJsonBodyIsABadRequest(): void
    {
        $client = $this->createCleanClient();

        $this->post($client, '{not json');

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @param list<array{discordId: string, balance: string}> $wallets
     */
    private function payload(array $wallets): string
    {
        return json_encode(['event' => 'transaction.committed', 'transactionId' => 'x', 'type' => 'bank_to_user', 'amount' => '1', 'createdAt' => '2026-09-30T12:00:00Z', 'wallets' => $wallets], \JSON_THROW_ON_ERROR);
    }

    private function post(KernelBrowser $client, string $body, ?string $signature = null, ?int $timestamp = null): void
    {
        $timestamp ??= time();
        $signature ??= 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        $client->request('POST', '/webhooks/youl-coin', server: [
            'HTTP_X_YOUL_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_YOUL_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }
}
