<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends events to Umami's /api/send over the internal obs_analytics network.
 *
 * The visitor's User-Agent and IP are forwarded so Umami attributes the event
 * to the same session as the JS-snippet pageviews (it hashes IP + UA); the
 * payload itself stays anonymous.
 */
final readonly class UmamiAnalyticsTracker implements AnalyticsTrackerInterface
{
    // Umami drops events whose UA looks like a bot (isbot), so the no-request
    // fallback (CLI, workers) must be a plausible browser string
    private const string FALLBACK_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Symfony/UmamiAnalyticsTracker';

    public function __construct(
        private HttpClientInterface $umamiClient,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        #[Autowire(env: 'UMAMI_WEBSITE_ID')]
        private string $websiteId,
    ) {
    }

    public function track(string $event, array $data = []): void
    {
        if ('' === $this->websiteId) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        $headers = ['User-Agent' => $request?->headers->get('User-Agent') ?? self::FALLBACK_USER_AGENT];
        if (null !== $request?->getClientIp()) {
            $headers['X-Forwarded-For'] = $request->getClientIp();
        }

        try {
            $response = $this->umamiClient->request('POST', '/api/send', [
                'headers' => $headers,
                'json' => [
                    'type' => 'event',
                    'payload' => [
                        'website' => $this->websiteId,
                        'name' => $event,
                        'data' => $data,
                        'url' => $request?->getPathInfo() ?? '',
                        'hostname' => $request?->getHost() ?? '',
                    ],
                ],
            ]);
            // HttpClient is lazy: consume the response so transport/HTTP errors
            // surface here, inside the try, instead of at destruct time
            $response->getStatusCode();
        } catch (\Throwable $throwable) {
            $this->logger->warning('Umami event failed', [
                'event' => $event,
                'exception' => $throwable,
            ]);
        }
    }
}
