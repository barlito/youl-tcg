<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationRenderer;
use App\Service\Notification\NotificationService;
use App\Service\Realtime\RealtimeTopics;
use App\Service\Realtime\UserEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the real realtime/notification services for unit tests, over doubles.
 */
trait RealtimeTestTrait
{
    private function userEventPublisher(?HubInterface $hub = null): UserEventPublisher
    {
        $hub ??= $this->createStub(HubInterface::class);
        $hub->method('getPublicUrl')->willReturn('https://localhost/.well-known/mercure');

        return new UserEventPublisher($hub, new RealtimeTopics($hub), new NullLogger());
    }

    private function notificationService(?HubInterface $hub = null): NotificationService
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/boosters');

        return new NotificationService(
            $this->createStub(NotificationRepository::class),
            new NotificationRenderer($urlGenerator),
            $this->userEventPublisher($hub),
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-09-24 12:00:00', 'UTC'),
            new NullLogger(),
        );
    }
}
