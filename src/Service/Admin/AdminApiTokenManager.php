<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\AdminApiTokenInfo;
use App\Dto\Admin\GeneratedAdminApiToken;
use App\Enum\Admin\AdminApiScopeEnum;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One active admin-API token at a time (with its scopes), kept as a sha256 hash in a cache pool.
 */
final readonly class AdminApiTokenManager
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
     *
     * @param list<AdminApiScopeEnum> $scopes
     */
    public function generate(string $discordId, array $scopes): GeneratedAdminApiToken
    {
        $scopes = AdminApiScopeEnum::fromValues(array_map(static fn (AdminApiScopeEnum $scope): string => $scope->value, $scopes));
        if ([] === $scopes) {
            throw new \InvalidArgumentException('An admin API token needs at least one scope.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $this->clock->now()->modify(\sprintf('+%d seconds', self::TTL));

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set([
            'hash' => hash('sha256', $token),
            'generatedBy' => $discordId,
            'expiresAt' => $expiresAt->getTimestamp(),
            'scopes' => array_map(static fn (AdminApiScopeEnum $scope): string => $scope->value, $scopes),
        ]);
        $item->expiresAfter(self::TTL);
        $this->cache->save($item);

        return new GeneratedAdminApiToken($token, new AdminApiTokenInfo($discordId, $expiresAt, $scopes));
    }

    public function revoke(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
    }

    public function isValid(string $token): bool
    {
        return $this->validate($token) instanceof AdminApiTokenInfo;
    }

    public function validate(string $token): ?AdminApiTokenInfo
    {
        $info = $this->read();
        if (null === $info || '' === $token) {
            return null;
        }

        return hash_equals($info['hash'], hash('sha256', $token)) ? $info['info'] : null;
    }

    public function activeTokenInfo(): ?AdminApiTokenInfo
    {
        return $this->read()['info'] ?? null;
    }

    /**
     * @return array{hash: string, info: AdminApiTokenInfo}|null
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

        // tokens stored before scopes existed were import tokens
        $scopes = AdminApiScopeEnum::fromValues(\is_array($data['scopes'] ?? null) ? $data['scopes'] : [AdminApiScopeEnum::IMPORT->value]);
        if ([] === $scopes) {
            return null;
        }

        return [
            'hash' => $hash,
            'info' => new AdminApiTokenInfo($generatedBy, new \DateTimeImmutable('@' . $expiresAt), $scopes),
        ];
    }
}
