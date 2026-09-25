<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Repository\NotificationRepository;
use App\Service\Realtime\UserEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Notification center: persists an entry, then pushes it live (private topic
 * of the recipient, or the public broadcast topic when there is none).
 *
 * Call it AFTER the business transaction committed. Like the realtime layer,
 * it is best effort: a failure is logged, never propagated to the action
 * that triggered it.
 */
final readonly class NotificationService
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private NotificationRenderer $renderer,
        private UserEventPublisher $publisher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar|null> $payload
     * @param bool                       $alreadyRead the player caused it in this very request and
     *                                                already saw the outcome: keep it in the history,
     *                                                but no badge and no toast
     */
    public function notify(?DiscordUser $recipient, NotificationTypeEnum $type, array $payload, bool $alreadyRead = false): ?Notification
    {
        try {
            $now = $this->clock->now();
            $notification = new Notification($recipient, $type, $payload, $now);
            if ($alreadyRead && $recipient instanceof DiscordUser) {
                $notification->markRead($now);
            }

            $this->entityManager->persist($notification);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('Notification "{type}" not stored: {message}', [
                'type' => $type->value,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }

        [$icon, $text, $link] = $this->renderer->describe($type, $payload);
        $event = [
            'id' => $notification->getId(),
            'icon' => $icon,
            'title' => 'Notification',
            'message' => $text,
            'link' => $link,
            'silent' => $alreadyRead,
        ];

        if ($recipient instanceof DiscordUser) {
            $this->publisher->publish($recipient, UserEventEnum::NOTIFICATION, $event);
        } else {
            $this->publisher->publishBroadcast(UserEventEnum::NOTIFICATION, $event);
        }

        return $notification;
    }

    public function countUnread(DiscordUser $discordUser): int
    {
        return $this->notificationRepository->countUnreadFor($discordUser);
    }

    public function markAllRead(DiscordUser $discordUser): void
    {
        $now = $this->clock->now();

        $this->entityManager->wrapInTransaction(function () use ($discordUser, $now): void {
            $this->notificationRepository->markAllReadFor($discordUser, $now);
            $discordUser->setNotificationsSeenAt($now);
            $this->entityManager->flush();
        });
    }

    /**
     * Marks one entry read and returns it. Someone else's notification is
     * treated as unknown. A broadcast has no per-player row: opening it
     * leaves the "seen" marker alone (only "mark all as read" moves it).
     */
    public function open(DiscordUser $discordUser, string $notificationId): ?Notification
    {
        $notification = $this->notificationRepository->findOneVisibleTo($discordUser, $notificationId);

        if ($notification instanceof Notification && !$notification->isBroadcast() && !$notification->getReadAt() instanceof \DateTimeImmutable) {
            $notification->markRead($this->clock->now());
            $this->entityManager->flush();
        }

        return $notification;
    }
}
