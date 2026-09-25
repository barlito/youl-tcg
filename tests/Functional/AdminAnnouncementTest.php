<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Announcement;
use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Tests\Support\SpyHub;
use App\Twig\Components\NotificationBell;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class AdminAnnouncementTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string ADMIN = '188967649332428800';
    private const string PLAYER = '195659530363731968';

    public function testABroadcastIsOneEntryPushedLiveAndLoggedWithItsAuthor(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->authenticateClient($client, self::ADMIN);
        $title = 'Maintenance ' . uniqid();

        $crawler = $client->request('GET', '/admin/announcements');
        self::assertResponseIsSuccessful();
        $this->hub()->reset();

        $client->submit($crawler->selectButton('Envoyer l\'annonce')->form([
            'announcement[title]' => $title,
            'announcement[message]' => "Ce soir 22h.\nPrévoyez des chips.",
            'announcement[link]' => '/univers',
            'announcement[target][mode]' => 'all',
        ]));
        self::assertResponseRedirects('/admin/announcements');

        $notifications = $this->announcementNotifications($title);
        $this->assertCount(1, $notifications);
        $this->assertTrue($notifications[0]->isBroadcast());
        $this->assertSame('/univers', $notifications[0]->getPayload()['link']);

        $events = $this->hub()->getEvents();
        $this->assertCount(1, $events);
        $this->assertFalse($this->hub()->getUpdates()[0]->isPrivate());
        $this->assertSame($title, $events[0]['payload']['title']);
        $this->assertSame("Ce soir 22h.\nPrévoyez des chips.", $events[0]['payload']['message']);
        $this->assertSame('/univers', $events[0]['payload']['link']);

        $announcement = $this->entityManager()->getRepository(Announcement::class)->findOneBy(['title' => $title]);
        $this->assertNotNull($announcement);
        $this->assertSame(self::ADMIN, $announcement->getAuthor()?->getDiscordId());
        $this->assertTrue($announcement->isBroadcast());
        $this->assertSame(1, $announcement->getSentCount());

        $crawler = $client->request('GET', '/admin/announcements');
        $this->assertStringContainsString($title, $crawler->filter('[data-testid="announcement-history"]')->text());

        // full, read-only send log
        $crawler = $client->request('GET', '/admin/send-log?query=' . urlencode($title));
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Tous les joueurs', $crawler->filter('table')->text());
        $client->request('GET', '/admin/send-log/' . $announcement->getId());
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/send-log/new');
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testASelectionGetsOnePersonalEntryEach(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $alice = $this->createPlayer('Alice');
        $bob = $this->createPlayer('Bob');
        $title = 'Bravo ' . uniqid();

        $crawler = $client->request('GET', '/admin/announcements');
        $client->submit($crawler->selectButton('Envoyer l\'annonce')->form([
            'announcement[title]' => $title,
            'announcement[message]' => 'Vous êtes en finale.',
            'announcement[target][mode]' => 'selection',
            'announcement[target][recipients]' => [$alice->getDiscordId(), $bob->getDiscordId()],
        ]));
        self::assertResponseRedirects('/admin/announcements');

        $notifications = $this->announcementNotifications($title);
        $this->assertCount(2, $notifications);
        $recipients = array_map(static fn (Notification $notification): ?string => $notification->getRecipient()?->getDiscordId(), $notifications);
        sort($recipients);
        $expected = [$alice->getDiscordId(), $bob->getDiscordId()];
        sort($expected);
        $this->assertSame($expected, $recipients);
        $this->assertNull($notifications[0]->getPayload()['link']);

        $announcement = $this->entityManager()->getRepository(Announcement::class)->findOneBy(['title' => $title]);
        $this->assertNotNull($announcement);
        $this->assertSame(2, $announcement->getSentCount());
        $this->assertCount(2, $announcement->getRecipients());
    }

    public function testASelectionNeedsAtLeastOnePlayer(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $title = 'Personne ' . uniqid();

        $crawler = $client->request('GET', '/admin/announcements');
        $crawler = $client->submit($crawler->selectButton('Envoyer l\'annonce')->form([
            'announcement[title]' => $title,
            'announcement[message]' => 'Test',
            'announcement[target][mode]' => 'selection',
        ]));

        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Choisis au moins un joueur.', $crawler->filter('body')->text());
        $this->assertSame([], $this->announcementNotifications($title));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'external site' => ['https://evil.com/boosters'];
        yield 'javascript' => ['javascript:alert(document.cookie)'];
        yield 'protocol-relative' => ['//evil.com'];
        yield 'backslash' => ['/\\evil.com'];
    }

    #[DataProvider('refusedLinks')]
    public function testANonInternalLinkIsRefused(string $link): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $title = 'Piège ' . uniqid();

        $crawler = $client->request('GET', '/admin/announcements');
        $crawler = $client->submit($crawler->selectButton('Envoyer l\'annonce')->form([
            'announcement[title]' => $title,
            'announcement[message]' => 'Clique !',
            'announcement[link]' => $link,
            'announcement[target][mode]' => 'all',
        ]));

        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Seuls les liens internes au site sont acceptés', $crawler->filter('body')->text());
        $this->assertSame([], $this->announcementNotifications($title));
    }

    public function testAnAbsoluteLinkOnTheAppHostIsStoredAsAPath(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $title = 'Absolu ' . uniqid();

        $crawler = $client->request('GET', '/admin/announcements');
        $client->submit($crawler->selectButton('Envoyer l\'annonce')->form([
            'announcement[title]' => $title,
            'announcement[message]' => 'Regarde le classement',
            'announcement[link]' => 'http://localhost/classement?page=2',
            'announcement[target][mode]' => 'all',
        ]));

        self::assertResponseRedirects('/admin/announcements');
        $this->assertSame('/classement?page=2', $this->announcementNotifications($title)[0]->getPayload()['link']);
    }

    public function testACrossOriginPostIsRejectedByCsrf(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $title = 'CSRF ' . uniqid();

        $crawler = $client->request('GET', '/admin/announcements');
        $form = $crawler->selectButton('Envoyer l\'annonce')->form([
            'announcement[title]' => $title,
            'announcement[message]' => 'Forgé',
            'announcement[target][mode]' => 'all',
        ]);
        $client->request('POST', '/admin/announcements', $form->getPhpValues(), server: [
            'HTTP_ORIGIN' => 'https://evil.com',
            'HTTP_REFERER' => 'https://evil.com/trap',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        $this->assertNotSame(302, $client->getResponse()->getStatusCode());
        $this->assertSame([], $this->announcementNotifications($title));
    }

    public function testTheScreenIsAdminOnly(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::PLAYER);

        $client->request('GET', '/admin/announcements');
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('POST', '/admin/announcements', ['announcement' => ['title' => 'x', 'message' => 'y', 'target' => ['mode' => 'all']]]);
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testThePlayerSeesPlainEscapedText(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $player = $this->createPlayer('Escape');
        $title = '<script>alert(1)</script> ' . uniqid();

        $crawler = $client->request('GET', '/admin/announcements');
        $client->submit($crawler->selectButton('Envoyer l\'annonce')->form([
            'announcement[title]' => $title,
            'announcement[message]' => '<img src=x onerror=alert(2)>',
            'announcement[target][mode]' => 'selection',
            'announcement[target][recipients]' => [$player->getDiscordId()],
        ]));
        self::assertResponseRedirects('/admin/announcements');

        $this->authenticateClient($client, $player->getDiscordId());
        $rendered = (string) $this->createLiveComponent(NotificationBell::class, client: $client)->call('toggle')->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered);
        $this->assertStringNotContainsString('<img src=x', $rendered);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $rendered);
    }

    /**
     * @return list<Notification>
     */
    private function announcementNotifications(string $title): array
    {
        $this->entityManager()->clear();
        $notifications = $this->entityManager()->getRepository(Notification::class)->findBy(['type' => NotificationTypeEnum::ANNOUNCEMENT]);

        return array_values(array_filter(
            $notifications,
            static fn (Notification $notification): bool => ($notification->getPayload()['title'] ?? null) === $title,
        ));
    }

    private function createPlayer(string $name): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId((string) random_int(10 ** 17, 10 ** 18 - 1))->setUsername($name . ' ' . uniqid());
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function hub(): SpyHub
    {
        return self::getContainer()->get(SpyHub::class);
    }
}
