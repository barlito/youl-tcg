<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Repository\BoosterRepository;
use App\Service\Booster\BoosterClaimService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BoosterOpenPageTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string USER_WITHOUT_INVENTORY = '195659530363731968';

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();
        $booster = $this->firstPublishedBooster();

        $client->request('GET', '/boosters/' . $booster->getId() . '/open');

        $this->assertContains($client->getResponse()->getStatusCode(), [302, 307]);
    }

    public function testRedirectsToTheGridWhenUserDoesNotOwnThePack(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $client->request('GET', '/boosters/' . $booster->getId() . '/open');

        $this->assertResponseRedirects('/boosters');
    }

    public function testMalformedBoosterIdIsAPlainNotFound(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $client->request('GET', '/boosters/not-a-uuid/open');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRendersTheOpeningPageWhenOwned(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        static::getContainer()->get(BoosterClaimService::class)->claim($user, $booster);

        $client->request('GET', '/boosters/' . $booster->getId() . '/open');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('pack-opening-3d', $content);
        $this->assertStringContainsString('/models/pack-wide.gltf', $content);
        $this->assertStringContainsString($booster->getExtension()->getName(), $content);
        $this->assertStringContainsString('data-testid="open-pack"', $content);
        $this->assertStringContainsString('data-testid="opening-slots"', $content);
    }

    private function firstPublishedBooster(): Booster
    {
        foreach (static::getContainer()->get(BoosterRepository::class)->findPublished() as $booster) {
            if (str_starts_with($booster->getExtension()->getName(), 'Cyberpunk')) {
                return $booster;
            }
        }

        $this->fail('Fixture booster for the Cyberpunk extension not found, load the alice fixtures first.');
    }
}
