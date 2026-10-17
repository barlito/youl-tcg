<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\DiscordUser;
use App\Enum\Realtime\UserEventEnum;
use App\Service\Realtime\UserEventPublisher;
use App\Tests\Support\SpyHub;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\ProtocolVersion;

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

    public function testThePublisherTokenIsAnRfc9068AccessTokenOfTheApp(): void
    {
        self::bootKernel();

        $this->assertSame(ProtocolVersion::V1, $this->hub()->getProtocolVersion());
        $this->assertSame('__Secure-mercure_access_token', $this->hub()->getCookieName());

        $parts = explode('.', $this->hub()->getProvider()->getJwt());
        $this->assertCount(3, $parts);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true, flags: \JSON_THROW_ON_ERROR);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($header);
        $this->assertIsArray($claims);

        $this->assertSame('at+jwt', $header['typ']);
        // must equal the hub's `issuer` block and `resource_identifier` (Caddyfile)
        $this->assertSame('https://localhost', $claims['iss']);
        $this->assertSame('https://localhost/.well-known/mercure', $claims['aud']);
        // client credentials: the app is its own subject
        $this->assertSame('youl-tcg', $claims['sub']);
        $this->assertSame('youl-tcg', $claims['client_id']);
        $this->assertIsNumeric($claims['exp']);
        $this->assertSame([[
            'type' => 'https://mercure.rocks/authorization-detail',
            'actions' => ['publish'],
            'topics' => [['match' => '*']],
        ]], $claims['authorization_details']);
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
