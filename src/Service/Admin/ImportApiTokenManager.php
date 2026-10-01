<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\GeneratedImportToken;
use App\Dto\Admin\ImportTokenInfo;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One active import-API token at a time, kept as a sha256 hash in a cache pool.
 */
final readonly class ImportApiTokenManager
{
    public const int TTL = 3600;

    private const string CACHE_KEY = 'import_api_token';

    public function __construct(
        #[Autowire(service: 'cache.import_api')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Replaces (invalidates) any previous token; the plain value is returned once and never stored.
     */
    public function generate(string $discordId): GeneratedImportToken
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $this->clock->now()->modify(\sprintf('+%d seconds', self::TTL));

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set([
            'hash' => hash('sha256', $token),
            'generatedBy' => $discordId,
            'expiresAt' => $expiresAt->getTimestamp(),
        ]);
        $item->expiresAfter(self::TTL);
        $this->cache->save($item);

        return new GeneratedImportToken($token, new ImportTokenInfo($discordId, $expiresAt));
    }

    public function revoke(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
    }

    public function isValid(string $token): bool
    {
        return $this->validate($token) instanceof ImportTokenInfo;
    }

    public function validate(string $token): ?ImportTokenInfo
    {
        $info = $this->read();
        if (null === $info || '' === $token) {
            return null;
        }

        return hash_equals($info['hash'], hash('sha256', $token)) ? $info['info'] : null;
    }

    public function activeTokenInfo(): ?ImportTokenInfo
    {
        return $this->read()['info'] ?? null;
    }

    /**
     * @return array{hash: string, info: ImportTokenInfo}|null
     */
    private function read(): ?array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $data = $item->isHit() ? $item->get() : null;

        if (!\is_array($data)) {
            return null;
        }

        $hash = $data['hash'] ?? null;
        $generatedBy = $data['generatedBy'] ?? null;
        $expiresAt = $data['expiresAt'] ?? null;
        if (!\is_string($hash) || !\is_string($generatedBy) || !\is_int($expiresAt)) {
            return null;
        }

        if ($expiresAt <= $this->clock->now()->getTimestamp()) {
            return null;
        }

        return [
            'hash' => $hash,
            'info' => new ImportTokenInfo($generatedBy, new \DateTimeImmutable('@' . $expiresAt)),
        ];
    }
}
