<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\DiscordUser;
use App\Repository\DiscordUserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AppScopedRolesTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string ADMIN_DISCORD_ID = '188967649332428800';

    private const string PLAYER_DISCORD_ID = '195659530363731968';

    private const string GHOST_DISCORD_ID = '424242424242424242';

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testCoinAdminRoleAloneGivesNoAdminAccess(): void
    {
        $this->authenticateClient($this->client, self::PLAYER_DISCORD_ID, ['ROLE_ADMIN']);

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAppAdminRoleGivesAdminAccess(): void
    {
        $this->authenticateClient($this->client, self::ADMIN_DISCORD_ID, ['ROLE_ADMIN', 'ROLE_YTCG_ADMIN']);

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function testSyncRemovesAdminWhenTheTokenNoLongerCarriesTheAppRole(): void
    {
        $this->authenticateClient($this->client, self::ADMIN_DISCORD_ID, ['ROLE_ADMIN']);

        $this->client->request('GET', '/boosters');

        $this->assertNotContains('ROLE_ADMIN', $this->user(self::ADMIN_DISCORD_ID)->getRoles());
    }

    public function testSyncGrantsAdminWhenTheTokenCarriesTheAppRole(): void
    {
        $this->authenticateClient($this->client, self::PLAYER_DISCORD_ID, ['ROLE_YTCG_ADMIN']);

        $this->client->request('GET', '/boosters');

        $this->assertContains('ROLE_ADMIN', $this->user(self::PLAYER_DISCORD_ID)->getRoles());
    }

    public function testCreatedPlayerGetsOnlyTheAppRoles(): void
    {
        $ghost = new DiscordUser()->setDiscordId(self::GHOST_DISCORD_ID)->setUsername('Ghost');
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($ghost, [
            'username' => 'Ghost',
            'roles' => ['ROLE_ADMIN', 'ROLE_YTCG_ADMIN'],
        ]);
        $this->client->getCookieJar()->set(new Cookie('jwt', $token));

        $this->client->request('GET', '/boosters');

        self::assertResponseRedirects('http://localhost/boosters');
        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], $this->sorted($this->user(self::GHOST_DISCORD_ID)->getRoles()));
    }

    private function user(string $discordId): DiscordUser
    {
        $user = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);
        \assert($user instanceof DiscordUser);
        static::getContainer()->get('doctrine')->getManager()->refresh($user);

        return $user;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function sorted(array $roles): array
    {
        sort($roles);

        return $roles;
    }
}
