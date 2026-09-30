<?php

declare(strict_types=1);

namespace App\Service\Coin;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class WalletBalances
{
    private const int TTL = 60;
    private const int UNAVAILABLE_TTL = 10;

    public function __construct(
        private YoulCoinClient $client,
        private CacheInterface $cache,
    ) {
    }

    public function get(string $discordId): ?CoinAmount
    {
        $minor = $this->cache->get($this->key($discordId), function (ItemInterface $item) use ($discordId): ?string {
            $balance = $this->client->getBalance($discordId);
            $item->expiresAfter($balance instanceof CoinAmount ? self::TTL : self::UNAVAILABLE_TTL);

            return $balance?->minor;
        });

        return null === $minor ? null : CoinAmount::fromMinor($minor);
    }

    public function store(string $discordId, CoinAmount $balance): void
    {
        // beta INF forces the callback to run: overwrites the cached entry
        $this->cache->get($this->key($discordId), static function (ItemInterface $item) use ($balance): string {
            $item->expiresAfter(self::TTL);

            return $balance->minor;
        }, \INF);
    }

    public function forget(string $discordId): void
    {
        $this->cache->delete($this->key($discordId));
    }

    private function key(string $discordId): string
    {
        return 'wallet_balance.' . $discordId;
    }
}
