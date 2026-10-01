<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Admin\ImportApiTokenManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ImportApiAuthTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use ImportApiTestTrait;

    public function testValidTokenGivesAccess(): void
    {
        $client = self::createClient();

        $this->apiRequest($client, 'GET', '/api/admin/extensions', $this->newToken());

        self::assertResponseStatusCodeSame(200);
    }

    public function testMissingHeaderIsUnauthorizedJson(): void
    {
        $client = self::createClient();

        $body = $this->apiRequest($client, 'GET', '/api/admin/extensions', null);

        self::assertResponseStatusCodeSame(401);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function testWrongTokenAndMalformedHeaderAreUnauthorized(): void
    {
        $client = self::createClient();
        $this->newToken();

        $this->apiRequest($client, 'GET', '/api/admin/extensions', 'not-the-token');
        self::assertResponseStatusCodeSame(401);

        $client->request('GET', '/api/admin/extensions', server: ['HTTP_AUTHORIZATION' => 'Basic abc']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokedTokenIsUnauthorized(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        static::getContainer()->get(ImportApiTokenManager::class)->revoke();

        $this->apiRequest($client, 'GET', '/api/admin/extensions', $token);

        self::assertResponseStatusCodeSame(401);
    }

    public function testRegeneratedTokenInvalidatesTheOldOne(): void
    {
        $client = self::createClient();
        $old = $this->newToken();
        $new = $this->newToken();

        $this->apiRequest($client, 'GET', '/api/admin/extensions', $old);
        self::assertResponseStatusCodeSame(401);

        $this->apiRequest($client, 'GET', '/api/admin/extensions', $new);
        self::assertResponseStatusCodeSame(200);
    }

    public function testExpiredTokenIsUnauthorized(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        self::mockTime(new \DateTimeImmutable('+2 hours'));
        $this->apiRequest($client, 'GET', '/api/admin/extensions', $token);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheTokenDoesNotOpenTheBackOffice(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        foreach (['/admin', '/admin/api-import', '/admin/card'] as $uri) {
            $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $this->assertNotSame(200, $client->getResponse()->getStatusCode(), $uri);
        }
    }

    public function testUnknownRouteUnderTheApiAnswersJson(): void
    {
        $client = self::createClient();

        $body = $this->apiRequest($client, 'GET', '/api/admin/nothing', $this->newToken());

        self::assertResponseStatusCodeSame(404);
        $this->assertSame('Http error', $body['error']);
    }
}
