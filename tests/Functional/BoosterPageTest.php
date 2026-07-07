<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BoosterPageTest extends WebTestCase
{
    use JwtAuthTrait;

    public function testBoostersPageRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/boosters');

        $this->assertContains($client->getResponse()->getStatusCode(), [302, 307]);
    }

    public function testBoostersPageListsPublishedBoosters(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/boosters');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('section h1', 'Boosters');
        $this->assertStringContainsString('Récupérer', $client->getResponse()->getContent());
        $this->assertGreaterThan(0, $crawler->filter('[data-live-action-param="claimBooster"]')->count());
    }
}
