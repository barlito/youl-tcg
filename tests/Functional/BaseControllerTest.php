<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BaseControllerTest extends WebTestCase
{
    public function testHomepageRedirectsWhenNoJwtCookie(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        // Stateless JWT firewall: missing cookie triggers JwtNotFound
        // listener, which redirects to the external SSO refresh endpoint.
        self::assertResponseRedirects();
    }
}
