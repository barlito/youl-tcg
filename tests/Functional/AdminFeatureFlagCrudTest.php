<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FeatureFlag;
use App\Enum\FeatureEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminFeatureFlagCrudTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string NON_ADMIN = '195659530363731968';

    public function testTheIndexListsBothFlagsWithoutCreation(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/admin/feature-flag');

        self::assertResponseIsSuccessful();
        $content = $crawler->filter('main, #main, body')->first()->text();
        $this->assertStringContainsString(FeatureEnum::TRADES->label(), $content);
        $this->assertStringContainsString(FeatureEnum::RECYCLING->label(), $content);
        $this->assertCount(0, $crawler->filter('a.action-new'));
        $this->assertCount(0, $crawler->filter('.action-delete'));
        $this->assertStringContainsString('Fonctionnalités', $crawler->filter('#main-menu')->text());
    }

    public function testTogglingAFlagPersists(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/admin/feature-flag/recycling/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="FeatureFlag"]')->form();
        $form['FeatureFlag[enabled]']->untick();
        $client->submit($form);
        self::assertResponseRedirects();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $this->assertFalse($entityManager->find(FeatureFlag::class, 'recycling')?->isEnabled());

        $client->request('GET', '/recyclage');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreationAndDeletionAreDisabled(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $client->request('GET', '/admin/feature-flag/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testANonAdminIsDenied(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::NON_ADMIN);

        $client->request('GET', '/admin/feature-flag/recycling/edit');

        self::assertResponseStatusCodeSame(403);
    }
}
