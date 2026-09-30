<?php

declare(strict_types=1);

namespace App\Service\Coin;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YoulCoinClient
{
    public function __construct(
        #[Autowire(service: 'youl_coin.client')]
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Null = the coin is unavailable. A player without wallet yet has a zero balance.
     */
    public function getBalance(string $discordId): ?CoinAmount
    {
        try {
            $response = $this->httpClient->request('GET', \sprintf('/api/user/%s/wallet', rawurlencode($discordId)), [
                'headers' => ['Accept' => 'application/ld+json'],
            ]);

            if (404 === $response->getStatusCode()) {
                return CoinAmount::fromMinor('0');
            }

            return CoinAmount::fromMinor((string) ($response->toArray()['amount'] ?? ''));
        } catch (ExceptionInterface | \InvalidArgumentException $exception) {
            $this->logger->warning('Youl Coin balance unavailable: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
