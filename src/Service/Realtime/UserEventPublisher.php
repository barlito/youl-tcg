<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\DiscordUser;
use App\Enum\Realtime\UserEventEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Pushes an event to a player's private topic. Call it AFTER the business
 * transaction committed: a rolled back action must never be announced.
 * Realtime is best effort: a hub failure is logged, never rethrown.
 */
final readonly class UserEventPublisher
{
    public function __construct(
        private HubInterface $hub,
        private RealtimeTopics $topics,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publish(DiscordUser $discordUser, UserEventEnum $type, array $payload = []): void
    {
        try {
            $this->hub->publish(new Update(
                $this->topics->forUser($discordUser),
                json_encode(['type' => $type->value, 'payload' => $payload], \JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $exception) {
            $this->logger->warning('Realtime event "{type}" not published: {message}', [
                'type' => $type->value,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
