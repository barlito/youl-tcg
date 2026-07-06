<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\UserBooster;
use App\Repository\BoosterRepository;
use App\Twig\Components\BoosterHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BoosterHubComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    // Juju has no UserBooster fixture, unlike Barlito who starts with a stocked inventory.
    private const string USER_WITHOUT_INVENTORY = '195659530363731968';

    public function testClaimBoosterCreditsTheInventoryAndDecrementsTheQuota(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);

        $userBooster = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $user, 'booster' => $booster])
        ;

        $this->assertNotNull($userBooster);
        $this->assertSame(1, $userBooster->getQuantity());
        $this->assertNull($component->component()->error);

        // The owned count is surfaced on the pack card and totalled in the hero.
        $rendered = (string) $component->render();
        $this->assertStringContainsString('◈ 1 pack à ouvrir', $rendered);
        $this->assertStringContainsString('◈ 1 pack en stock', $rendered);
    }

    public function testEmptyInventoryShowsAnExplicitZeroStockState(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $rendered = (string) $component->render();

        $this->assertStringContainsString('◈ Aucun pack', $rendered);
        $this->assertStringContainsString('◈ 0 pack en stock', $rendered);
    }

    public function testClaimBoosterWithMalformedIdReportsNotFoundInsteadOfCrashing(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => 'not-a-uuid']);

        $this->assertSame('Booster introuvable.', $component->component()->error);
    }

    public function testOwnedDrawableBoosterRendersAnOpenLink(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);

        $rendered = (string) $component->render();
        // The "Ouvrir" control is now a link to the dedicated opening page.
        $this->assertStringContainsString('/boosters/' . $booster->getId() . '/open', $rendered);
    }

    public function testBoosterOverExtensionWithoutPublishedCardsIsNotDrawable(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $drawable = $component->component()->getDrawableBoosterIds();

        $cyberpunk = null;
        $bleach = null;
        foreach (static::getContainer()->get(BoosterRepository::class)->findPublished() as $booster) {
            if (str_starts_with($booster->getExtension()->getName(), 'Cyberpunk')) {
                $cyberpunk = $booster;
            }
            if (str_starts_with($booster->getExtension()->getName(), 'Bleach')) {
                $bleach = $booster;
            }
        }

        $this->assertNotNull($cyberpunk, 'Cyberpunk booster fixture missing.');
        $this->assertNotNull($bleach, 'Bleach booster fixture missing.');
        // Cyberpunk has published cards → drawable; Bleach has none → "à venir".
        $this->assertArrayHasKey((string) $cyberpunk->getId(), $drawable);
        $this->assertArrayNotHasKey((string) $bleach->getId(), $drawable);

        // The rendered "Ouvrir" control for Bleach is disabled, no scary error needed.
        $rendered = (string) $component->render();
        $this->assertStringContainsString('À venir', $rendered);
    }

    private function firstPublishedBooster(): \App\Entity\Booster
    {
        // The Cyberpunk extension is the one with published cards in the fixtures.
        foreach (static::getContainer()->get(BoosterRepository::class)->findPublished() as $booster) {
            if (str_starts_with($booster->getExtension()->getName(), 'Cyberpunk')) {
                return $booster;
            }
        }

        $this->fail('Fixture booster for the Cyberpunk extension not found, load the alice fixtures first.');
    }
}
