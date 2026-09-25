<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\DiscordUser;
use App\Enum\Realtime\UserEventEnum;
use App\Service\Realtime\UserEventPublisher;
use App\Tests\Support\SpyHub;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Exception\RuntimeException;

final class UserEventPublisherTest extends KernelTestCase
{
    public function testAnEventGoesPrivatelyToThePlayerTopic(): void
    {
        self::bootKernel();
        $user = new DiscordUser()->setDiscordId('188967649332428800')->setUsername('Barlito');

        $this->publisher()->publish($user, UserEventEnum::TOAST, ['message' => 'Salut']);

        $updates = $this->hub()->getUpdates();
        $this->assertCount(1, $updates);
        $this->assertSame(['https://localhost/users/188967649332428800'], $updates[0]->getTopics());
        $this->assertTrue($updates[0]->isPrivate());
        $this->assertSame([['type' => 'toast', 'payload' => ['message' => 'Salut']]], $this->hub()->getEvents());
    }

    public function testAHubFailureNeverBreaksTheCaller(): void
    {
        self::bootKernel();
        $this->hub()->failWith(new RuntimeException('hub down'));

        $this->publisher()->publish(new DiscordUser()->setDiscordId('1')->setUsername('x'), UserEventEnum::INVENTORY_CHANGED);

        $this->assertSame([], $this->hub()->getUpdates());
    }

    private function publisher(): UserEventPublisher
    {
        return self::getContainer()->get(UserEventPublisher::class);
    }

    private function hub(): SpyHub
    {
        return self::getContainer()->get(SpyHub::class);
    }
}
