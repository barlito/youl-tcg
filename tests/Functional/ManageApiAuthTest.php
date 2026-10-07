<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Admin\AdminApiScopeEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManageApiAuthTest extends WebTestCase
{
    use ManageApiTestTrait;

    private const string ID = '0194c3a0-0000-7000-8000-000000000000';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function manageRoutes(): iterable
    {
        yield 'extension show' => ['GET', '/api/admin/extensions/some-slug'];
        yield 'extension update' => ['PATCH', '/api/admin/extensions/some-slug'];
        yield 'extension files' => ['POST', '/api/admin/extensions/some-slug/files'];
        yield 'extension publish' => ['POST', '/api/admin/extensions/some-slug/publish'];
        yield 'banners list' => ['GET', '/api/admin/extensions/some-slug/banners'];
        yield 'banners create' => ['POST', '/api/admin/extensions/some-slug/banners'];
        yield 'card show' => ['GET', '/api/admin/cards/' . self::ID];
        yield 'card update' => ['PATCH', '/api/admin/cards/' . self::ID];
        yield 'card files' => ['POST', '/api/admin/cards/' . self::ID . '/files'];
        yield 'booster list' => ['GET', '/api/admin/boosters'];
        yield 'booster show' => ['GET', '/api/admin/boosters/' . self::ID];
        yield 'booster create' => ['POST', '/api/admin/boosters'];
        yield 'booster update' => ['PATCH', '/api/admin/boosters/' . self::ID];
        yield 'booster files' => ['POST', '/api/admin/boosters/' . self::ID . '/files'];
        yield 'settings show' => ['GET', '/api/admin/settings'];
        yield 'settings update' => ['PATCH', '/api/admin/settings'];
        yield 'features list' => ['GET', '/api/admin/features'];
        yield 'feature update' => ['PATCH', '/api/admin/features/trades'];
    }

    #[DataProvider('manageRoutes')]
    public function testWithoutATokenEveryManageRouteIsUnauthorized(string $method, string $uri): void
    {
        $client = self::createClient();

        $body = $this->jsonRequest($client, $method, $uri, null, []);

        self::assertResponseStatusCodeSame(401);
        $this->assertSame('Unauthorized', $body['error']);
    }

    #[DataProvider('manageRoutes')]
    public function testImportAndStatsScopesAreForbiddenOnEveryManageRoute(string $method, string $uri): void
    {
        $client = self::createClient();
        $token = $this->newToken([AdminApiScopeEnum::IMPORT, AdminApiScopeEnum::STATS]);

        $body = $this->jsonRequest($client, $method, $uri, $token, []);

        self::assertResponseStatusCodeSame(403);
        $this->assertSame('Http error', $body['error']);
    }

    #[DataProvider('manageRoutes')]
    public function testTheManageScopeOpensEveryManageRoute(string $method, string $uri): void
    {
        $client = self::createClient();
        $token = $this->newToken([AdminApiScopeEnum::MANAGE]);

        $this->jsonRequest($client, $method, $uri, $token, []);

        $this->assertNotContains($client->getResponse()->getStatusCode(), [401, 403, 405], $method . ' ' . $uri);
    }

    public function testTheManageScopeAloneDoesNotOpenTheImportOrStatsRoutes(): void
    {
        $client = self::createClient();
        $token = $this->newToken([AdminApiScopeEnum::MANAGE]);

        foreach ([['GET', '/api/admin/extensions'], ['POST', '/api/admin/extensions'], ['GET', '/api/admin/extensions/x/cards'], ['POST', '/api/admin/extensions/x/cards'], ['GET', '/api/admin/stats']] as [$method, $uri]) {
            $this->jsonRequest($client, $method, $uri, $token, []);
            $this->assertSame(403, $client->getResponse()->getStatusCode(), $method . ' ' . $uri);
        }
    }

    public function testTheManageScopeDoesNotGiveTheBackOffice(): void
    {
        $client = self::createClient();
        $token = $this->newToken([AdminApiScopeEnum::MANAGE]);

        foreach (['/admin', '/admin/api', '/admin/card'] as $uri) {
            $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $this->assertNotSame(200, $client->getResponse()->getStatusCode(), $uri);
        }
    }

    public function testImportAndStatsRoutesKeepTheirOwnScope(): void
    {
        $client = self::createClient();

        $this->jsonRequest($client, 'GET', '/api/admin/extensions', $this->newToken([AdminApiScopeEnum::IMPORT]));
        self::assertResponseStatusCodeSame(200);

        $this->jsonRequest($client, 'GET', '/api/admin/extensions/x/cards', $this->newToken([AdminApiScopeEnum::IMPORT]));
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest($client, 'GET', '/api/admin/stats?sections=meta', $this->newToken([AdminApiScopeEnum::STATS]));
        self::assertResponseStatusCodeSame(200);
    }

    public function testThereIsNoDeleteEndpoint(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $extension = $this->makeExtension();
        $card = $this->makeCard($extension);
        $booster = $this->makeBooster($extension);

        foreach (['/api/admin/extensions/' . $extension->getSlug(), '/api/admin/cards/' . $card->getId(), '/api/admin/boosters/' . $booster->getId(), '/api/admin/extensions/' . $extension->getSlug() . '/banners', '/api/admin/extensions/' . $extension->getSlug() . '/cards', '/api/admin/settings', '/api/admin/features/trades'] as $uri) {
            $this->jsonRequest($client, 'DELETE', $uri, $token);
            $this->assertSame(405, $client->getResponse()->getStatusCode(), $uri);
        }

        $this->assertNotNull($this->em()->find(\App\Entity\Card::class, $card->getId()));
    }
}
