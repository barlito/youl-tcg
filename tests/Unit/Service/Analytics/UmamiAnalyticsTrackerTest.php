<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Analytics;

use App\Service\Analytics\UmamiAnalyticsTracker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class UmamiAnalyticsTrackerTest extends TestCase
{
    private const string WEBSITE_ID = '019807c8-0000-7000-8000-000000000000';

    public function testSendsEventPayloadWithForwardedClientHeaders(): void
    {
        $capturedOptions = null;
        $capturedUrl = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions, &$capturedUrl): MockResponse {
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('{"ok":true}');
        }, 'http://umami.test');

        $tracker = new UmamiAnalyticsTracker(
            $httpClient,
            $this->createRequestStack(),
            $this->createStub(LoggerInterface::class),
            self::WEBSITE_ID,
        );

        $tracker->track('booster_opened', ['extension' => 'Origines', 'holo_count' => 2]);

        $this->assertSame(1, $httpClient->getRequestsCount());
        $this->assertSame('http://umami.test/api/send', $capturedUrl);
        $this->assertIsArray($capturedOptions);

        $payload = json_decode((string) $capturedOptions['body'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('event', $payload['type']);
        $this->assertSame(self::WEBSITE_ID, $payload['payload']['website']);
        $this->assertSame('booster_opened', $payload['payload']['name']);
        $this->assertSame(['extension' => 'Origines', 'holo_count' => 2], $payload['payload']['data']);
        $this->assertSame('/boosters', $payload['payload']['url']);
        $this->assertSame('ytcg.test', $payload['payload']['hostname']);

        $headers = implode("\n", $capturedOptions['headers']);
        $this->assertStringContainsString('User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', $headers);
        $this->assertStringContainsString('X-Forwarded-For: 203.0.113.7', $headers);
    }

    public function testFallsBackToBrowserLikeUserAgentWithoutRequest(): void
    {
        $capturedOptions = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse('{"ok":true}');
        }, 'http://umami.test');

        $tracker = new UmamiAnalyticsTracker(
            $httpClient,
            new RequestStack(),
            $this->createStub(LoggerInterface::class),
            self::WEBSITE_ID,
        );

        $tracker->track('booster_claimed');

        $this->assertSame(1, $httpClient->getRequestsCount());
        $this->assertIsArray($capturedOptions);
        // Umami drops bot-looking user agents (isbot): the fallback must look like a browser
        $this->assertStringContainsString('User-Agent: Mozilla/5.0', implode("\n", $capturedOptions['headers']));
    }

    public function testDoesNothingWhenWebsiteIdIsEmpty(): void
    {
        $httpClient = new MockHttpClient([], 'http://umami.test');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $tracker = new UmamiAnalyticsTracker($httpClient, $this->createRequestStack(), $logger, '');

        $tracker->track('booster_claimed', ['booster' => 'Origines']);

        $this->assertSame(0, $httpClient->getRequestsCount());
    }

    public function testTransportErrorIsSwallowedAndLogged(): void
    {
        $httpClient = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']),
            'http://umami.test',
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Umami event failed', $this->callback(
            static fn (array $context): bool => 'booster_opened' === $context['event'] && $context['exception'] instanceof \Throwable,
        ));

        $tracker = new UmamiAnalyticsTracker($httpClient, $this->createRequestStack(), $logger, self::WEBSITE_ID);

        $tracker->track('booster_opened');
    }

    private function createRequestStack(): RequestStack
    {
        $request = Request::create('https://ytcg.test/boosters', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
            'REMOTE_ADDR' => '203.0.113.7',
        ]);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }
}
