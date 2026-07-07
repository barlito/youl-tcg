<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmptyHubTest extends WebTestCase
{
    use JwtAuthTrait;

    public function testBoostersPageWithNoBoosterAtAll(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['user_booster', 'booster_claim', 'booster_opening_card', 'booster_opening', 'booster', 'user_card', 'card', 'extension_banner', 'extension'] as $table) {
            $em->getConnection()->executeStatement("DELETE FROM $table");
        }

        $client->request('GET', '/boosters');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/univers');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/collection');
        self::assertResponseIsSuccessful();
    }
}
