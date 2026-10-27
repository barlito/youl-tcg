<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\UserBooster;
use App\Repository\BoosterClaimRepository;
use App\Repository\BoosterRepository;
use App\Twig\Components\BoosterHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
        $this->assertStringContainsString('1 en stock', $rendered);
        $this->assertStringContainsString('En stock 1', $this->stockTile($rendered));
    }

    public function testEmptyInventoryShowsAnExplicitZeroStockState(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $rendered = (string) $component->render();

        $this->assertStringContainsString('0 en stock', $rendered);
        $this->assertStringContainsString('En stock 0', $this->stockTile($rendered));
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
            'Ce pack n\'est pas récupérable pour le moment.',
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
        // no fixture slot advertises a one-of-one chance
        $this->assertStringNotContainsString('◆ Unique', $rendered);
    }

    public function testDropRatesPanelAdvertisesTheUniqueChance(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->firstPublishedBooster()->setRarityRates([
            ['rarities' => ['common' => 100], 'holoChance' => 0, 'uniqueChance' => 25],
        ]);
        $entityManager->flush();

        $rendered = (string) $this->createLiveComponent(BoosterHub::class, client: $client)->render();

        // 25 per 10 000 read by the player as a percentage
        $this->assertStringContainsString('◆ Unique 0.25%', $rendered);
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

    public function testABoosterWithNothingToDrawCannotBeClaimed(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $cyberpunk = $this->firstPublishedBooster();
        $bleach = null;
        foreach (static::getContainer()->get(BoosterRepository::class)->findPublished() as $booster) {
            if ('Bleach' === $booster->getExtension()->getName()) {
                $bleach = $booster;
            }
        }
        $this->assertNotNull($bleach, 'Bleach booster fixture missing.');

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $rendered = (string) $component->render();

        // "À venir" alone: no claim button next to it
        $this->assertStringContainsString('data-live-booster-id-param="' . $cyberpunk->getId() . '"', $rendered);
        $this->assertStringNotContainsString('data-live-booster-id-param="' . $bleach->getId() . '"', $rendered);

        // a forged action is refused without burning a daily claim
        $component->call('claimBooster', ['boosterId' => (string) $bleach->getId()]);

        $this->assertSame('Ce pack n\'est pas récupérable pour le moment.', $component->component()->error);
        $this->assertSame(0, static::getContainer()->get(BoosterClaimRepository::class)->countSince($user, new \DateTimeImmutable('-1 day')));
        $this->assertNull(static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $user, 'booster' => $bleach]));
    }

    public function testThePackFilterKeepsTheStockedPacksOrTheIncompleteUniverses(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $all = $component->render()->crawler()->filter('[data-testid="booster-card"]')->count();
        $this->assertStringContainsString('En stock · 0', $component->render()->crawler()->filter('[data-testid="pack-filter-stock"]')->text());

        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);
        $component->call('filterPacks', ['filter' => 'stock']);

        $this->assertSame('stock', $component->component()->filter);
        $crawler = $component->render()->crawler();
        $this->assertSame('true', $crawler->filter('[data-testid="pack-filter-stock"]')->attr('aria-pressed'));
        $this->assertCount(1, $crawler->filter('[data-testid="boosters-grid"] [data-testid="booster-card"]'));
        $this->assertStringContainsString('/boosters/' . $booster->getId() . '/open', $crawler->filter('[data-testid="boosters-grid"]')->html());
        $this->assertStringContainsString('Tous · ' . $all, $crawler->filter('[data-testid="pack-filter-tous"]')->text());

        // an incomplete universe = published cards the player does not own yet: the fixtures leave some
        $component->call('filterPacks', ['filter' => 'incomplets']);
        $incomplete = $component->render()->crawler()->filter('[data-testid="booster-card"]');
        $this->assertGreaterThan(0, $incomplete->count());
        $this->assertStringNotContainsString('Univers complet', $incomplete->text());

        // an unknown value falls back to every pack
        $component->call('filterPacks', ['filter' => 'nope']);
        $this->assertSame('tous', $component->component()->filter);
        $this->assertCount($all, $component->render()->crawler()->filter('[data-testid="booster-card"]'));
    }

    public function testTheFilterIsReadFromTheUrl(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $crawler = $client->request('GET', '/boosters?filtre=stock');

        self::assertResponseIsSuccessful();
        $this->assertSame('true', $crawler->filter('[data-testid="pack-filter-stock"]')->attr('aria-pressed'));
        $this->assertCount(0, $crawler->filter('[data-testid="booster-card"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="pack-filter-empty"]'));
    }

    /**
     * Text of the "en stock" hero tile, whitespace normalized.
     */
    private function stockTile(string $rendered): string
    {
        return new Crawler($rendered)->filter('[data-testid="total-owned"]')->text(normalizeWhitespace: true);
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
