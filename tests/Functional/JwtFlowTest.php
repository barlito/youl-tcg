<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\DiscordUser;
use App\Repository\DiscordUserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class JwtFlowTest extends WebTestCase
{
    private const string EXISTING_DISCORD_ID = '188967649332428800';

    private const string GHOST_DISCORD_ID = '424242424242424242';

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testExpiredTokenRedirectsToRefreshUrl(): void
    {
        $user = $this->existingUser();
        $token = $this->tokenManager()->createFromPayload($user, ['exp' => time() - 3600]);
        $this->client->getCookieJar()->set(new Cookie('jwt', $token));

        $this->client->request('GET', '/boosters');

        self::assertResponseRedirects($this->refreshUrl('http://localhost/boosters'));
    }

    public function testMalformedTokenRedirectsInsteadOfCrashing(): void
    {
        $this->client->getCookieJar()->set(new Cookie('jwt', 'not.a-real.jwt-token'));

        $this->client->request('GET', '/boosters');

        self::assertResponseRedirects($this->refreshUrl('http://localhost/boosters'));
    }

    public function testBadlySignedTokenRedirectsInsteadOfCrashing(): void
    {
        $token = $this->tokenManager()->create($this->existingUser());
        $parts = explode('.', $token);
        $parts[2] = str_repeat('A', \strlen($parts[2]));
        $this->client->getCookieJar()->set(new Cookie('jwt', implode('.', $parts)));

        $this->client->request('GET', '/boosters');

        self::assertResponseRedirects($this->refreshUrl('http://localhost/boosters'));
    }

    public function testExpiredTokenOnLiveComponentSendsThePlayerBackToThePage(): void
    {
        $this->requestNotificationBellWithExpiredToken('/collection?filtre=owned');

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('X-Live-Redirect', '1');
        self::assertResponseHeaderSame('Location', $this->refreshUrl('http://localhost/collection?filtre=owned'));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusablePageUrls(): iterable
    {
        yield 'missing' => [null];
        yield 'other host' => ['https://evil.example/'];
        yield 'protocol-relative' => ['//evil.example/'];
        yield 'component endpoint' => ['/_components/NotificationBell'];
    }

    #[DataProvider('unusablePageUrls')]
    public function testExpiredTokenOnLiveComponentFallsBackToTheHomepage(?string $pageUrl): void
    {
        $this->requestNotificationBellWithExpiredToken($pageUrl);

        self::assertResponseHeaderSame('Location', $this->refreshUrl('http://localhost/'));
    }

    public function testUnknownUserIsAutoCreatedThenRequestIsReplayed(): void
    {
        $ghost = new DiscordUser()
            ->setDiscordId(self::GHOST_DISCORD_ID)
            ->setUsername('Ghost')
        ;
        $token = $this->tokenManager()->createFromPayload($ghost, ['username' => 'Ghost']);
        $this->client->getCookieJar()->set(new Cookie('jwt', $token));

        $this->client->request('GET', '/boosters');

        self::assertResponseRedirects('http://localhost/boosters');

        $created = $this->userRepository()->find(self::GHOST_DISCORD_ID);
        $this->assertInstanceOf(DiscordUser::class, $created);
        $this->assertSame('Ghost', $created->getUsername());
        $this->assertSame(['ROLE_USER'], $created->getRoles());

        // Replaying the request with the same cookie now authenticates.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testTokenWithoutUsernameClaimDoesNotCreateUser(): void
    {
        $ghost = new DiscordUser()
            ->setDiscordId(self::GHOST_DISCORD_ID)
            ->setUsername('Ghost')
        ;
        $token = $this->tokenManager()->createFromPayload($ghost);
        $this->client->getCookieJar()->set(new Cookie('jwt', $token));

        $this->client->request('GET', '/boosters');

        self::assertResponseRedirects($this->refreshUrl('http://localhost/boosters'));
        $this->assertNull($this->userRepository()->find(self::GHOST_DISCORD_ID));
    }

    private function requestNotificationBellWithExpiredToken(?string $pageUrl): void
    {
        $token = $this->tokenManager()->createFromPayload($this->existingUser(), ['exp' => time() - 3600]);
        $this->client->getCookieJar()->set(new Cookie('jwt', $token));

        $headers = ['HTTP_ACCEPT' => 'application/vnd.live-component+html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        if (null !== $pageUrl) {
            $headers['HTTP_X_LIVE_URL'] = $pageUrl;
        }

        $this->client->request('GET', '/_components/NotificationBell', ['props' => '{}'], [], $headers);
    }

    private function existingUser(): DiscordUser
    {
        $user = $this->userRepository()->find(self::EXISTING_DISCORD_ID);

        if (!$user instanceof DiscordUser) {
            throw new \LogicException(\sprintf('Fixture user "%s" not found, load the alice fixtures first.', self::EXISTING_DISCORD_ID));
        }

        return $user;
    }

    private function userRepository(): DiscordUserRepository
    {
        return static::getContainer()->get(DiscordUserRepository::class);
    }

    private function tokenManager(): JWTTokenManagerInterface
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class);
    }

    private function refreshUrl(string $targetUri): string
    {
        $refreshTokenUrl = $_ENV['REFRESH_TOKEN_URL'] ?? null;
        \assert(\is_string($refreshTokenUrl));

        return $refreshTokenUrl . '?_target_path=' . urlencode($targetUri);
    }
}
