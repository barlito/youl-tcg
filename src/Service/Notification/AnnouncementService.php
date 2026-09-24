<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Dto\Admin\AnnouncementDraft;
use App\Dto\Admin\RecipientTarget;
use App\Entity\Announcement;
use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Exception\Notification\NotificationRefusedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Admin announcements: one broadcast entry for every player, or one
 * personal entry per selected player, pushed live by NotificationService.
 * Each send is logged (Announcement) with its author.
 */
final readonly class AnnouncementService
{
    public function __construct(
        private NotificationService $notificationService,
        private InternalLinkPolicy $internalLinkPolicy,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Expects a validated draft; the link is checked again (and reduced to a
     * path) here, whatever the caller did.
     */
    public function send(AnnouncementDraft $draft, ?DiscordUser $author): Announcement
    {
        $title = trim((string) $draft->title);
        $message = trim((string) $draft->message);
        $rawLink = trim((string) $draft->link);
        $link = '' !== $rawLink ? $this->internalLinkPolicy->toInternalPath($rawLink) : null;

        if ('' === $title || '' === $message || ('' !== $rawLink && null === $link) || $draft->target->isNone()) {
            throw new NotificationRefusedException('Annonce incomplète ou lien refusé.');
        }

        $payload = ['title' => $title, 'message' => $message, 'link' => $link];
        $sent = $this->dispatch($draft->target, NotificationTypeEnum::ANNOUNCEMENT, $payload);

        $announcement = new Announcement(
            NotificationTypeEnum::ANNOUNCEMENT,
            $title,
            $message,
            $link,
            $author,
            $draft->target->getSelectedRecipients(),
            $sent,
            $this->clock->now(),
        );
        $this->entityManager->persist($announcement);
        $this->entityManager->flush();

        return $announcement;
    }

    /**
     * @param array<string, scalar|null> $payload
     *
     * @return int entries actually stored
     */
    private function dispatch(RecipientTarget $target, NotificationTypeEnum $type, array $payload): int
    {
        if ($target->isAll()) {
            return $this->notificationService->notify(null, $type, $payload) instanceof Notification ? 1 : 0;
        }

        $sent = 0;
        foreach ($target->getSelectedRecipients() as $recipient) {
            if ($this->notificationService->notify($recipient, $type, $payload) instanceof Notification) {
                ++$sent;
            }
        }

        return $sent;
    }
}
