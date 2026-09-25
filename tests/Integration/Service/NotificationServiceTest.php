<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Service\Notification\NotificationService;
use App\Tests\Support\SpyHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Exception\RuntimeException;

final class NotificationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private NotificationService $service;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(NotificationService::class);
    }

    public function testAPersonalNotificationIsStoredThenPushedPrivately(): void
    {
        $user = $this->createUser();

        $notification = $this->service->notify($user, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 14]);

        $this->assertNotNull($notification);
        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Notification::class)->find($notification->getId());
        $this->assertNotNull($stored);
        $this->assertSame($user->getDiscordId(), $stored->getRecipient()?->getDiscordId());
        $this->assertNull($stored->getReadAt());

        $updates = $this->hub()->getUpdates();
        $this->assertCount(1, $updates);
        $this->assertSame(['https://localhost/users/' . $user->getDiscordId()], $updates[0]->getTopics());
        $this->assertTrue($updates[0]->isPrivate());

        $event = $this->hub()->getEvents()[0];
        $this->assertSame('notification', $event['type']);
        $this->assertSame('Palier de série 14 jours atteint : choisis ton pack bonus', $event['payload']['message']);
        $this->assertSame('/boosters', $event['payload']['link']);
        $this->assertFalse($event['payload']['silent']);
    }

    public function testABroadcastHasNoRecipientAndGoesToThePublicTopic(): void
    {
        $notification = $this->service->notify(null, NotificationTypeEnum::UNIQUE_PULLED, ['playerId' => '42', 'playerName' => 'Alice', 'universe' => 'Cosmos']);

        $this->assertNotNull($notification);
        $this->assertTrue($notification->isBroadcast());

        $updates = $this->hub()->getUpdates();
        $this->assertCount(1, $updates);
        $this->assertSame(['https://localhost/broadcast'], $updates[0]->getTopics());
        $this->assertFalse($updates[0]->isPrivate());
        $this->assertSame('Alice a tiré une carte unique dans Cosmos', $this->hub()->getEvents()[0]['payload']['message']);
        $this->assertSame('/joueur/42', $this->hub()->getEvents()[0]['payload']['link']);
    }

    public function testASelfInitiatedCreditIsKeptReadAndSilent(): void
    {
        $user = $this->createUser();

        $notification = $this->service->notify($user, NotificationTypeEnum::BOOSTER_CREDITED, ['boosterName' => 'Pack Cosmos', 'quantity' => 2, 'channel' => 'code'], alreadyRead: true);

        $this->assertNotNull($notification?->getReadAt());
        $this->assertSame(0, $this->service->countUnread($user));
        $this->assertTrue($this->hub()->getEvents()[0]['payload']['silent']);
        $this->assertSame('2 packs « Pack Cosmos » ajoutés à ton stock (code)', $this->hub()->getEvents()[0]['payload']['message']);
    }

    public function testAHubFailureKeepsTheNotification(): void
    {
        $user = $this->createUser();
        $this->hub()->failWith(new RuntimeException('hub down'));

        $notification = $this->service->notify($user, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 7]);

        $this->assertNotNull($notification);
        $this->assertSame(1, $this->service->countUnread($user));
    }

    public function testUnreadCountsPersonalEntriesAndBroadcastsSinceTheLastVisit(): void
    {
        $user = $this->createUser(seenAt: new \DateTimeImmutable('-1 hour'));
        $other = $this->createUser();

        $this->store($user, '-10 minutes');
        $this->store($user, '-5 minutes', read: true);
        $this->store($other, '-5 minutes'); // someone else's: never counted
        $this->store(null, '-2 minutes'); // broadcast after the last visit
        $this->store(null, '-2 hours'); // broadcast before it

        $this->assertSame(2, $this->service->countUnread($user));
    }

    public function testANewPlayerDoesNotInheritOldBroadcasts(): void
    {
        $this->store(null, '-3 days');
        $user = $this->createUser();
        $user->setCreatedAt(new \DateTime('-1 day'));
        $this->entityManager->flush();

        $this->assertSame(0, $this->service->countUnread($user));
    }

    public function testMarkAllReadClearsPersonalEntriesAndBroadcastsOfThatPlayerOnly(): void
    {
        $user = $this->createUser(seenAt: new \DateTimeImmutable('-1 day'));
        $other = $this->createUser(seenAt: new \DateTimeImmutable('-1 day'));
        $this->store($user, '-1 minute');
        $this->store($other, '-1 minute');
        $this->store(null, '-1 minute');

        $this->service->markAllRead($user);

        $this->assertSame(0, $this->service->countUnread($user));
        $this->assertSame(2, $this->service->countUnread($other), 'Another player\'s state is untouched.');
    }

    public function testOpeningMarksOnlyOwnEntries(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $mine = $this->store($user, '-1 minute');
        $theirs = $this->store($other, '-1 minute');

        $this->assertNull($this->service->open($user, (string) $theirs->getId()), 'Someone else\'s notification resolves as unknown.');
        $this->assertNull($theirs->getReadAt());
        $this->assertNull($this->service->open($user, 'not-a-uuid'));

        $this->assertSame($mine, $this->service->open($user, (string) $mine->getId()));
        $this->assertNotNull($mine->getReadAt());
    }

    private function store(?DiscordUser $recipient, string $createdAt, bool $read = false): Notification
    {
        $notification = new Notification($recipient, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 7], new \DateTimeImmutable($createdAt));
        if ($read) {
            $notification->markRead(new \DateTimeImmutable());
        }
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    private function createUser(?\DateTimeImmutable $seenAt = null): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId('notif-' . uniqid())->setUsername('Notif tester')->setNotificationsSeenAt($seenAt);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function hub(): SpyHub
    {
        return self::getContainer()->get(SpyHub::class);
    }
}
