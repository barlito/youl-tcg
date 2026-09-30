<?php

declare(strict_types=1);

namespace App\Service\Coin;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YoulCoinClient
{
    private const int BANK_WALLET_TTL = 86400;
    private const int BANK_WALLET_UNAVAILABLE_TTL = 10;

    public function __construct(
        #[Autowire(service: 'youl_coin.client')]
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Null = the coin is unavailable. A player without wallet yet has a zero balance.
     */
    public function getBalance(string $discordId): ?CoinAmount
    {
        try {
            $response = $this->httpClient->request('GET', $this->userWalletPath($discordId), [
                'headers' => ['Accept' => 'application/ld+json'],
            ]);

            if (404 === $response->getStatusCode()) {
                return CoinAmount::fromMinor('0');
            }

            return CoinAmount::fromMinor((string) ($response->toArray()['amount'] ?? ''));
        } catch (ExceptionInterface | \InvalidArgumentException $exception) {
            $this->logUnavailable($exception);

            return null;
        }
    }

    /**
     * Player-to-bank payment. The externalIdentifier makes a retry idempotent on the coin side;
     * the player token proves the player consents to the debit.
     */
    public function debitToBank(string $discordId, CoinAmount $amount, string $externalIdentifier, string $playerToken): CoinPayment
    {
        $walletIds = [$this->fetchWalletId($this->userWalletPath($discordId)), $this->getBankWalletId()];

        if (\in_array(null, $walletIds, true)) {
            return CoinPayment::unavailable();
        }

        try {
            $response = $this->httpClient->request('POST', '/api/transactions', [
                'headers' => ['Accept' => 'application/ld+json', 'X-Player-Token' => $playerToken],
                'json' => [
                    'amount' => $amount->minor,
                    'walletFrom' => '/api/wallets/' . $walletIds[0],
                    'walletTo' => '/api/wallets/' . $walletIds[1],
                    'type' => 'purchase',
                    'externalIdentifier' => $externalIdentifier,
                ],
            ]);
            $status = $response->getStatusCode();

            if (201 === $status) {
                $transactionId = $response->toArray()['id'] ?? null;

                // recorded but unreadable: the reconciliation finds it by externalIdentifier
                return \is_string($transactionId) ? CoinPayment::paid($transactionId) : CoinPayment::uncertain();
            }

            // 4xx: the coin looked at the request and said no; anything else leaves the outcome unknown
            return $status >= 400 && $status < 500 ? CoinPayment::refused($status) : CoinPayment::uncertain();
        } catch (ExceptionInterface $exception) {
            $this->logUnavailable($exception);

            return CoinPayment::uncertain();
        }
    }

    public function findTransaction(string $externalIdentifier): CoinPayment
    {
        try {
            $response = $this->httpClient->request('GET', '/api/transactions', [
                'headers' => ['Accept' => 'application/ld+json'],
                'query' => ['externalIdentifier' => $externalIdentifier],
            ]);
            $members = $response->toArray();
            $transaction = ($members['hydra:member'] ?? $members['member'] ?? [])[0] ?? null;

            if (null === $transaction) {
                return CoinPayment::notFound();
            }

            return \is_string($transaction['id'] ?? null) ? CoinPayment::paid($transaction['id']) : CoinPayment::unavailable();
        } catch (ExceptionInterface $exception) {
            $this->logUnavailable($exception);

            return CoinPayment::unavailable();
        }
    }

    private function getBankWalletId(): ?string
    {
        return $this->cache->get('coin_bank_wallet_id', function (ItemInterface $item): ?string {
            $walletId = $this->fetchWalletId('/api/bank/wallet');
            $item->expiresAfter(null === $walletId ? self::BANK_WALLET_UNAVAILABLE_TTL : self::BANK_WALLET_TTL);

            return $walletId;
        });
    }

    private function fetchWalletId(string $path): ?string
    {
        try {
            $wallet = $this->httpClient->request('GET', $path, ['headers' => ['Accept' => 'application/ld+json']])->toArray();

            return \is_string($wallet['id'] ?? null) ? $wallet['id'] : null;
        } catch (ExceptionInterface $exception) {
            $this->logUnavailable($exception);

            return null;
        }
    }

    private function userWalletPath(string $discordId): string
    {
        return \sprintf('/api/user/%s/wallet', rawurlencode($discordId));
    }

    private function logUnavailable(\Throwable $exception): void
    {
        $this->logger->warning('Youl Coin unavailable: {message}', [
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
