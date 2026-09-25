<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\DiscordUser;
use Symfony\Component\Mercure\HubInterface;

/**
 * Mercure topic IRIs, rooted on the public origin of the hub (the app's own
 * origin: the hub is served by FrankenPHP under /.well-known/mercure).
 */
final readonly class RealtimeTopics
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    /**
     * Private topic of one player: only their subscription JWT lists it.
     */
    public function forUser(DiscordUser $discordUser): string
    {
        return \sprintf('%s/users/%s', $this->origin(), rawurlencode($discordUser->getDiscordId()));
    }

    /**
     * Public topic every player listens to: carries no personal data.
     */
    public function broadcast(): string
    {
        return $this->origin() . '/broadcast';
    }

    /**
     * Private topics the player's subscription JWT is allowed to receive.
     *
     * @return list<string>
     */
    public function privateTopicsFor(DiscordUser $discordUser): array
    {
        return [$this->forUser($discordUser)];
    }

    /**
     * Every topic the player's browser listens to.
     *
     * @return list<string>
     */
    public function subscriptionsFor(DiscordUser $discordUser): array
    {
        return [...$this->privateTopicsFor($discordUser), $this->broadcast()];
    }

    private function origin(): string
    {
        $url = parse_url($this->hub->getPublicUrl());

        if (!\is_array($url) || !isset($url['scheme'], $url['host'])) {
            throw new \LogicException('MERCURE_PUBLIC_URL must be an absolute URL.');
        }

        return \sprintf('%s://%s%s', $url['scheme'], $url['host'], isset($url['port']) ? ':' . $url['port'] : '');
    }
}
