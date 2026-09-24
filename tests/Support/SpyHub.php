<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Test double of the Mercure hub: records published updates instead of
 * reaching the network, and delegates everything else (public URL, JWT
 * factory, cookie name) to the real hub so subscription cookies stay real.
 */
final class SpyHub implements HubInterface
{
    /** @var list<Update> */
    private array $updates = [];

    private ?\Throwable $failure = null;

    public function __construct(
        private readonly HubInterface $inner,
    ) {
    }

    public function getPublicUrl(): string
    {
        return $this->inner->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->inner->getFactory();
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->inner->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->inner->getCookieName();
    }

    public function publish(Update $update): string
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        $this->updates[] = $update;

        return 'urn:uuid:' . \count($this->updates);
    }

    /**
     * @return list<Update>
     */
    public function getUpdates(): array
    {
        return $this->updates;
    }

    /**
     * Decoded {type, payload} bodies of the recorded updates.
     *
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    public function getEvents(): array
    {
        return array_map(
            static fn (Update $update): array => json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR),
            $this->updates,
        );
    }

    public function reset(): void
    {
        $this->updates = [];
        $this->failure = null;
    }

    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }
}
