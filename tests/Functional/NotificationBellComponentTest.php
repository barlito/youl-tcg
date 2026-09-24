<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Twig\Components\NotificationBell;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class NotificationBellComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string JUJU = '195659530363731968';
    private const string BARLITO = '188967649332428800';

    public function testTheHeaderShowsTheBellWithTheUnreadBadge(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::JUJU);
        $this->store($user, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 7]);
        $this->store(null, NotificationTypeEnum::UNIQUE_PULLED, ['playerId' => self::BARLITO, 'playerName' => 'Barlito', 'universe' => 'Cosmos']);

        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSame('2', trim($crawler->filter('[data-testid="notification-badge"]')->text()));
        // closed by default: no panel in the page
        $this->assertCount(0, $crawler->filter('[data-testid="notification-panel"]'));
    }

    public function testOpeningListsEntriesWithServerRenderedTextAndInternalLinks(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::JUJU);
        $this->store($user, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 7]);
        $this->store(null, NotificationTypeEnum::UNIQUE_PULLED, ['playerId' => self::BARLITO, 'playerName' => 'Barlito', 'universe' => 'Cosmos']);

        $component = $this->createLiveComponent(NotificationBell::class, client: $client);
        $crawler = $component->call('toggle')->render()->crawler();

        $items = $crawler->filter('[data-testid="notification-item"]');
        $this->assertCount(2, $items);
        $this->assertStringContainsString('Barlito a tiré une carte unique dans Cosmos', $items->eq(0)->text());
        $this->assertStringContainsString('Palier de série 7 jours atteint', $items->eq(1)->text());
        $this->assertCount(2, $crawler->filter('[data-testid="notification-item"][data-unread]'));
    }

    public function testMarkAllReadClearsTheBadge(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::JUJU);
        $this->store($user, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 7]);
        $this->store(null, NotificationTypeEnum::UNIQUE_PULLED, ['playerId' => self::BARLITO, 'playerName' => 'Barlito', 'universe' => 'Cosmos']);

        $component = $this->createLiveComponent(NotificationBell::class, client: $client);
        $crawler = $component->call('toggle')->call('markAllRead')->render()->crawler();

        $this->assertCount(0, $crawler->filter('[data-testid="notification-badge"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="notification-item"][data-unread]'));
        $this->assertCount(2, $crawler->filter('[data-testid="notification-item"]'), 'Read entries stay listed.');
    }

    public function testOpeningAnEntryMarksItReadAndFollowsItsLink(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::JUJU);
        $notification = $this->store($user, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 7]);

        $component = $this->createLiveComponent(NotificationBell::class, client: $client);
        $component->call('openNotification', ['id' => (string) $notification->getId()]);

        $this->assertResponseRedirects('/boosters');
        $this->assertNotNull($this->reload($notification)->getReadAt());
    }

    public function testAPlayerCannotSeeNorOpenSomeoneElsesNotification(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::JUJU);
        $barlito = static::getContainer()->get(EntityManagerInterface::class)->find(DiscordUser::class, self::BARLITO);
        $this->assertInstanceOf(DiscordUser::class, $barlito);
        $theirs = $this->store($barlito, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 21]);

        $component = $this->createLiveComponent(NotificationBell::class, client: $client);
        $crawler = $component->call('toggle')->render()->crawler();
        $this->assertCount(0, $crawler->filter('[data-testid="notification-item"]'));
        $this->assertStringNotContainsString('21 jours', $crawler->html());

        $component->call('openNotification', ['id' => (string) $theirs->getId()]);
        $this->assertNull($this->reload($theirs)->getReadAt());
        $this->assertCount(0, $component->render()->crawler()->filter('[data-testid="notification-panel"]'));
    }

    public function testTheDailyBoostersEntryIsComputedNotStored(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::JUJU);

        $crawler = $this->createLiveComponent(NotificationBell::class, client: $client)->call('toggle')->render()->crawler();

        // Juju has claims left today (test env: real daily quota)
        $this->assertCount(1, $crawler->filter('[data-testid="notification-daily-boosters"]'));
        $this->assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(Notification::class)->count([]));
        $this->assertBellValues($crawler);
    }

    private function assertBellValues(Crawler $crawler): void
    {
        $bell = $crawler->filter('[data-testid="notification-bell"]');
        $this->assertSame('2', $bell->attr('data-notification-bell-remaining-value'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $bell->attr('data-notification-bell-period-value'));
        $this->assertGreaterThan(0, (int) $bell->attr('data-notification-bell-seconds-value'));
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function store(?DiscordUser $recipient, NotificationTypeEnum $type, array $payload): Notification
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $notification = new Notification($recipient, $type, $payload, new \DateTimeImmutable());
        $entityManager->persist($notification);
        $entityManager->flush();

        return $notification;
    }

    private function reload(Notification $notification): Notification
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Notification::class, $notification->getId());
        $this->assertInstanceOf(Notification::class, $reloaded);

        return $reloaded;
    }
}
