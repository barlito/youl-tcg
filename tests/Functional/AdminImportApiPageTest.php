<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Admin\ImportApiTokenManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminImportApiPageTest extends WebTestCase
{
    use ImportApiTestTrait;
    use JwtAuthTrait;

    private const string PLAYER = '195659530363731968';

    public function testAdminGeneratesATokenShownOnceThenAccepted(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->authenticateClient($client);
        static::getContainer()->get(ImportApiTokenManager::class)->revoke();

        $crawler = $client->request('GET', '/admin/api-import');
        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="token-inactive"]'));

        $crawler = $client->submit($crawler->selectButton('Générer un token')->form());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-testid="token-value"]')->attr('value');
        $this->assertNotEmpty($token);
        $this->assertCount(1, $crawler->filter('[data-testid="token-active"]'));

        $manager = static::getContainer()->get(ImportApiTokenManager::class);
        $this->assertTrue($manager->isValid((string) $token));
        $this->assertSame('188967649332428800', $manager->activeTokenInfo()?->generatedBy);

        // never shown again
        $crawler = $client->request('GET', '/admin/api-import');
        $this->assertCount(0, $crawler->filter('[data-testid="generated-token"]'));
        $this->assertStringNotContainsString((string) $token, (string) $client->getResponse()->getContent());
    }

    public function testRegeneratingInvalidatesThePreviousTokenAndRevokeKillsTheActiveOne(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->authenticateClient($client);
        $manager = static::getContainer()->get(ImportApiTokenManager::class);
        $first = $this->newToken();

        $crawler = $client->request('GET', '/admin/api-import');
        $crawler = $client->submit($crawler->selectButton('Générer un token')->form());
        $second = (string) $crawler->filter('[data-testid="token-value"]')->attr('value');

        $this->assertFalse($manager->isValid($first));
        $this->assertTrue($manager->isValid($second));

        $client->submit($crawler->selectButton('Révoquer')->form());
        self::assertResponseRedirects('/admin/api-import');
        $this->assertFalse($manager->isValid($second));
        $this->assertNull($manager->activeTokenInfo());
    }

    public function testPostWithoutAValidCsrfTokenIsRefused(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        static::getContainer()->get(ImportApiTokenManager::class)->revoke();

        $client->request('POST', '/admin/api-import', ['action' => 'generate', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        $this->assertNull(static::getContainer()->get(ImportApiTokenManager::class)->activeTokenInfo());
    }

    public function testNonAdminIsRefused(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::PLAYER);

        $client->request('GET', '/admin/api-import');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/api-import', ['action' => 'generate']);
        self::assertResponseStatusCodeSame(403);
    }
}
