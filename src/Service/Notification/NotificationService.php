<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Dto\NotificationView;
use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Repository\NotificationBroadcastReadRepository;
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
        private NotificationBroadcastReadRepository $broadcastReadRepository,
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

        $content = $this->renderer->describe($type, $payload);
        // with a body (announcements), the text becomes the toast heading
        $event = [
            'id' => $notification->getId(),
            'icon' => $content->icon,
            'title' => null !== $content->body ? $content->text : 'Notification',
            'message' => $content->body ?? $content->text,
            'link' => $content->link,
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
            // the "seen" marker now covers every broadcast opened one by one
            $this->broadcastReadRepository->deleteFor($discordUser);
            $discordUser->setNotificationsSeenAt($now);
            $this->entityManager->flush();
        });
    }

    /**
     * The latest entries visible to the player, newest first, rendered with
     * their per-player read state.
     *
     * @return list<NotificationView>
     */
    public function latest(DiscordUser $discordUser, int $limit): array
    {
        $notifications = $this->notificationRepository->findLatestFor($discordUser, $limit);
        $opened = $this->broadcastReadRepository->findOpenedIds($discordUser, $notifications);

        return array_map(
            fn (Notification $notification): NotificationView => $this->renderer->render($notification, $discordUser, isset($opened[(string) $notification->getId()])),
            $notifications,
        );
    }

    /**
     * Marks read the unread entries among the latest $limit that have no
     * link: plain text, reading it in the open dropdown is all there is to do.
     *
     * @return list<string> ids of the entries marked read
     */
    public function markLinklessRead(DiscordUser $discordUser, int $limit): array
    {
        $now = $this->clock->now();
        $notifications = $this->notificationRepository->findLatestFor($discordUser, $limit);
        $opened = $this->broadcastReadRepository->findOpenedIds($discordUser, $notifications);
        $marked = [];

        foreach ($notifications as $notification) {
            $id = (string) $notification->getId();
            $hasLink = null !== $this->renderer->describe($notification->getType(), $notification->getPayload())->link;
            if ($hasLink || !$notification->isUnreadFor($discordUser, isset($opened[$id]))) {
                continue;
            }

            $this->markRead($discordUser, $notification, $now);
            $marked[] = $id;
        }

        $this->entityManager->flush();

        return $marked;
    }

    /**
     * Marks one entry read and returns it. Someone else's notification is
     * treated as unknown.
     */
    public function open(DiscordUser $discordUser, string $notificationId): ?Notification
    {
        $notification = $this->notificationRepository->findOneVisibleTo($discordUser, $notificationId);

        if ($notification instanceof Notification) {
            $this->markRead($discordUser, $notification, $this->clock->now());
            $this->entityManager->flush();
        }

        return $notification;
    }

    private function markRead(DiscordUser $discordUser, Notification $notification, \DateTimeImmutable $now): void
    {
        if ($notification->isBroadcast()) {
            // already covered by the "seen" marker: no row needed
            if (!$notification->isUnreadFor($discordUser)) {
                return;
            }

            $this->broadcastReadRepository->insertIgnore($notification, $discordUser, $now);

            return;
        }

        $notification->markRead($now);
    }
}
