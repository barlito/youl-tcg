<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\NotificationView;
use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Service\Booster\BoosterClaimService;
use App\Service\Notification\NotificationRenderer;
use App\Service\Notification\NotificationService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Header bell: unread badge, dropdown of the latest entries, "mark all as
 * read". Re-renders on the `live-updates:notification` realtime event.
 */
#[AsLiveComponent]
final class NotificationBell extends AbstractController
{
    use DefaultActionTrait;

    public const int LIMIT = 15;

    #[LiveProp]
    public bool $open = false;

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationRenderer $renderer,
        private readonly NotificationService $notificationService,
        private readonly BoosterClaimService $boosterClaimService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getUnreadCount(): int
    {
        return $this->notificationService->countUnread($this->getDiscordUser());
    }

    /**
     * @return list<NotificationView>
     */
    public function getNotifications(): array
    {
        $viewer = $this->getDiscordUser();

        return array_map(
            fn (Notification $notification): NotificationView => $this->renderer->render($notification, $viewer),
            $this->notificationRepository->findLatestFor($viewer, self::LIMIT),
        );
    }

    /**
     * Daily boosters are not stored as notifications: the entry is computed
     * on render, and the client times the "available again" toast from the
     * same server-side countdown as the hub.
     */
    public function getRemainingClaims(): int
    {
        return $this->boosterClaimService->getRemainingClaims($this->getDiscordUser());
    }

    public function getSecondsUntilReset(): int
    {
        return $this->boosterClaimService->getSecondsUntilReset();
    }

    /**
     * Quota period (Paris day) the client uses to toast only once a day.
     */
    public function getClaimPeriod(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
    }

    public function getNextClaimPeriod(): string
    {
        return $this->boosterClaimService->getNextResetTime()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
    }

    #[LiveAction]
    public function toggle(): void
    {
        $this->open = !$this->open;
    }

    #[LiveAction]
    public function close(): void
    {
        $this->open = false;
    }

    #[LiveAction]
    public function markAllRead(): void
    {
        $this->notificationService->markAllRead($this->getDiscordUser());
    }

    /**
     * Marks the entry read and follows its (server-rendered, internal) link,
     * if any.
     * Unknown ids and other players' entries only close the dropdown.
     */
    #[LiveAction]
    public function openNotification(#[LiveArg] string $id): ?RedirectResponse
    {
        $viewer = $this->getDiscordUser();
        $notification = $this->notificationService->open($viewer, $id);

        if (!$notification instanceof Notification) {
            $this->open = false;

            return null;
        }

        $link = $this->renderer->render($notification, $viewer)->link;

        // no link (plain announcement): marked read, the dropdown stays open
        return null !== $link ? $this->redirect($link) : null;
    }

    private function getDiscordUser(): DiscordUser
    {
        $user = $this->getUser();

        if (!$user instanceof DiscordUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
