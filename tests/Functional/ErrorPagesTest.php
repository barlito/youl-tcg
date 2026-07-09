<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les templates custom (templates/bundles/TwigBundle/Exception/) ne sont rendus
 * qu'en debug=false : on passe par la preview /_error/{code} (showException=false)
 * pour vérifier leur contenu, et par de vraies requêtes pour les codes HTTP.
 */
final class ErrorPagesTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string DISCORD_ID_JUJU = '195659530363731968';

    public function testCustom404Template(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/_error/404');

        self::assertResponseStatusCodeSame(404);
        $this->assertStringContainsString('CARTE INTROUVABLE', $crawler->html());
        // La page étend le layout : header + footer présents
        $this->assertStringContainsString('TRADING CARD GAME', $crawler->filter('header')->text());
        $this->assertStringContainsString('youl-made · non-officiel', $crawler->filter('footer')->text());
        $this->assertCount(1, $crawler->filter('main a[href="/"]'));
    }

    public function testCustom403Template(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/_error/403');

        self::assertResponseStatusCodeSame(403);
        $this->assertStringContainsString('ACCÈS REFUSÉ', $crawler->html());
        $this->assertStringContainsString('TRADING CARD GAME', $crawler->filter('header')->text());
    }

    public function testGenericErrorTemplateFor500(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/_error/500');

        self::assertResponseStatusCodeSame(500);
        $this->assertStringContainsString('mauvaise carte', $crawler->html());
        // Repli autonome : pas de layout partagé (rendable même si le layout est en cause)
        $this->assertCount(0, $crawler->filter('header'));
        $this->assertCount(1, $crawler->filter('main a[href="/"]'));
    }

    public function testGenericErrorTemplateCoversOtherCodes(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/_error/503');

        self::assertResponseStatusCodeSame(503);
        $this->assertStringContainsString('mauvaise carte', $crawler->html());
    }

    public function testUnknownRouteReturns404(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $this->authenticateClient($client);

        $client->request('GET', '/cette-page-n-existe-pas');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminAreaReturns403ForNonAdmin(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $this->authenticateClient($client, self::DISCORD_ID_JUJU);

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }
}
