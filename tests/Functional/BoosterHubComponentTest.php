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

    public function testNonClaimableBoosterIsHiddenUntilOwned(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $eventBooster = $this->createEventBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);

        // not owned → the event/code booster simply doesn't exist on the hub
        $this->assertStringNotContainsString('Pack Event Test', (string) $component->render());

        // …even so, a forged live claim is refused server-side, in French
        $component->call('claimBooster', ['boosterId' => (string) $eventBooster->getId()]);
        $this->assertSame(
            'Ce pack ne peut pas être récupéré ici — il se gagne en event ou via un code.',
            $component->component()->error,
        );

        // owning a copy reveals it: event chip, own name leading, extension in the eyebrow
        // (references re-fetched: the live component calls detached our entities)
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            new UserBooster()
                ->setDiscordUser($entityManager->getReference(\App\Entity\DiscordUser::class, $user->getDiscordId()))
                ->setBooster($entityManager->getReference(\App\Entity\Booster::class, $eventBooster->getId()))
                ->setQuantity(1),
        );
        $entityManager->flush();

        $rendered = (string) $this->createLiveComponent(BoosterHub::class, client: $client)->render();
        $this->assertStringContainsString('Pack Event Test', $rendered);
        $this->assertStringContainsString('data-testid="not-claimable"', $rendered);
        $this->assertStringContainsString('✕ Non récupérable', $rendered);
    }

    public function testDropRatesPanelExposesTheNormalisedRates(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $rendered = (string) $component->render();

        $this->assertStringContainsString('data-testid="drop-rates-toggle"', $rendered);
        $this->assertStringContainsString('Taux par carte', $rendered);
        // every fixture slot weight map normalises to percentages
        $this->assertStringContainsString('%', $rendered);
    }

    private function createEventBooster(): \App\Entity\Booster
    {
        $booster = new \App\Entity\Booster()
            ->setExtension($this->firstPublishedBooster()->getExtension())
            ->setName('Pack Event Test')
            ->setClaimable(false)
            ->setRarityRates([['rarities' => ['rare' => 100], 'holoChance' => 100]])
        ;
        $booster->setImageName('default_card.png');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($booster);
        $entityManager->flush();

        return $booster;
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
