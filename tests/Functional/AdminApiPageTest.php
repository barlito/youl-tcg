<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Admin\AdminApiScopeEnum;
use App\Service\Admin\AdminApiTokenManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminApiPageTest extends WebTestCase
{
    use ImportApiTestTrait;
    use JwtAuthTrait;

    private const string PLAYER = '195659530363731968';

    public function testAdminGeneratesATokenShownOnceThenAccepted(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->authenticateClient($client);
        static::getContainer()->get(AdminApiTokenManager::class)->revoke();

        $crawler = $client->request('GET', '/admin/api');
        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="token-inactive"]'));

        $crawler = $client->submit($crawler->selectButton('Générer un token')->form());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-testid="token-value"]')->attr('value');
        $this->assertNotEmpty($token);
        $this->assertCount(1, $crawler->filter('[data-testid="token-active"]'));

        $manager = static::getContainer()->get(AdminApiTokenManager::class);
        $this->assertTrue($manager->isValid((string) $token));
        $this->assertSame('188967649332428800', $manager->activeTokenInfo()?->generatedBy);

        // never shown again
        $crawler = $client->request('GET', '/admin/api');
        $this->assertCount(0, $crawler->filter('[data-testid="generated-token"]'));
        $this->assertStringNotContainsString((string) $token, (string) $client->getResponse()->getContent());
    }

    public function testRegeneratingInvalidatesThePreviousTokenAndRevokeKillsTheActiveOne(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->authenticateClient($client);
        $manager = static::getContainer()->get(AdminApiTokenManager::class);
        $first = $this->newToken();

        $crawler = $client->request('GET', '/admin/api');
        $crawler = $client->submit($crawler->selectButton('Générer un token')->form());
        $second = (string) $crawler->filter('[data-testid="token-value"]')->attr('value');

        $this->assertFalse($manager->isValid($first));
        $this->assertTrue($manager->isValid($second));

        $client->submit($crawler->selectButton('Révoquer')->form());
        self::assertResponseRedirects('/admin/api');
        $this->assertFalse($manager->isValid($second));
        $this->assertNull($manager->activeTokenInfo());
    }

    public function testPostWithoutAValidCsrfTokenIsRefused(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        static::getContainer()->get(AdminApiTokenManager::class)->revoke();

        $client->request('POST', '/admin/api', ['action' => 'generate', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        $this->assertNull(static::getContainer()->get(AdminApiTokenManager::class)->activeTokenInfo());
    }

    public function testNonAdminIsRefused(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::PLAYER);

        $client->request('GET', '/admin/api');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/api', ['action' => 'generate']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBothScopesAreCheckedByDefaultAndGrantedScopesAreShown(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->authenticateClient($client);
        $manager = static::getContainer()->get(AdminApiTokenManager::class);
        $manager->revoke();

        $crawler = $client->request('GET', '/admin/api');
        $this->assertCount(1, $crawler->filter('[data-testid="scope-import"][checked]'));
        $this->assertCount(1, $crawler->filter('[data-testid="scope-stats"][checked]'));

        $form = $crawler->selectButton('Générer un token')->form();
        $form['scopes'][1]->untick();
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="token-scope-import"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="token-scope-stats"]'));
        $this->assertSame([AdminApiScopeEnum::IMPORT], $manager->activeTokenInfo()?->scopes);
    }

    public function testGeneratingWithoutAnyScopeIsRefused(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->authenticateClient($client);
        $manager = static::getContainer()->get(AdminApiTokenManager::class);
        $manager->revoke();

        $crawler = $client->request('GET', '/admin/api');
        $form = $crawler->selectButton('Générer un token')->form();
        $form['scopes'][0]->untick();
        $form['scopes'][1]->untick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/api');
        $this->assertNull($manager->activeTokenInfo());
    }
}
