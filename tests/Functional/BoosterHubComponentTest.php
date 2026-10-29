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

        // The owned count is surfaced on the pack's « Ouvrir » and totalled in the hero.
        $rendered = (string) $component->render();
        $this->assertStringContainsString('Ouvrir · 1', new Crawler($rendered)->filter('[data-testid="boosters-grid"]')->text(normalizeWhitespace: true));
        $this->assertStringContainsString('En stock 1', $this->stockTile($rendered));
    }

    public function testEmptyInventoryShowsAnExplicitZeroStockState(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $rendered = (string) $component->render();

        $this->assertStringContainsString('En stock 0', $this->stockTile($rendered));
        // nothing to open: no « Prêts à ouvrir » row and no « Ouvrir » on the tiles
        $crawler = new Crawler($rendered);
        $this->assertCount(0, $crawler->filter('[data-testid="ready-packs"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="pack-open"]'));
    }

    public function testOwnedPacksAreListedFirstReadyToOpen(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);

        $ready = $component->render()->crawler()->filter('[data-testid="ready-packs"] [data-testid="ready-pack"]');
        $this->assertCount(1, $ready);
        $this->assertStringContainsString('×1', $ready->text());
        $this->assertSame('/boosters/' . $booster->getId() . '/open', $ready->filter('[data-testid="ready-open"]')->attr('href'));
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
        // the panel covers its own summary: it must close on any click, Escape or ✕
        $this->assertStringContainsString('data-controller="details-dismiss"', $rendered);
        $this->assertStringContainsString('data-testid="drop-rates-close"', $rendered);
        // every fixture slot weight map normalises to percentages
        $this->assertStringContainsString('%', $rendered);
        // no fixture slot advertises a one-of-one chance
        $this->assertStringNotContainsString('Unique 1/1', $rendered);
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
        $this->assertStringContainsString('<span class="unique-ic" aria-hidden="true">♛</span> Unique 1/1 · 0.25%', $rendered);
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

    public function testClaimAndOpenKeepTheirOwnSlotsAcrossAClaim(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $tile = $this->packTile($component->render()->crawler(), $booster);

        // 0 in stock: the corner « + » claims (with the claims left), no dead « Ouvrir · 0 »
        $claim = $tile->filter('[data-testid="pack-claim"]');
        $this->assertSame('claimBooster', $claim->attr('data-live-action-param'));
        $this->assertNull($claim->attr('disabled'));
        $this->assertStringContainsString('addAttribute(disabled)', (string) $claim->attr('data-loading'));
        $this->assertSame('2', $claim->filter('[data-testid="pack-claims-left"]')->text());
        $this->assertCount(0, $tile->filter('[data-testid="pack-open"]'));

        // after a claim the « + » is still the claim button, in the same corner: a second
        // click claims again instead of leading to the opening page
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);
        $tile = $this->packTile($component->render()->crawler(), $booster);

        $claim = $tile->filter('[data-testid="pack-claim"]');
        $this->assertSame('claimBooster', $claim->attr('data-live-action-param'));
        $this->assertSame('1', $claim->filter('[data-testid="pack-claims-left"]')->text());
        $this->assertStringContainsString('pack-tile__plus--ghost', (string) $claim->attr('class'), 'Already in stock: the « + » steps back.');
        $open = $tile->filter('[data-testid="pack-actions"] [data-testid="pack-open"]');
        $this->assertSame('a', $open->nodeName());
        $this->assertSame('/boosters/' . $booster->getId() . '/open', $open->attr('href'));
        $this->assertSame('Ouvrir · 1', $open->text(normalizeWhitespace: true));
    }

    public function testTheClaimButtonIsDisabledOnceTheDailyQuotaIsSpent(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);

        $tile = $this->packTile($component->render()->crawler(), $booster);
        $claim = $tile->filter('[data-testid="pack-claim"]');
        $this->assertNotNull($claim->attr('disabled'));
        $this->assertNull($claim->attr('data-live-action-param'));
        $this->assertSame('0', $claim->filter('[data-testid="pack-claims-left"]')->text());
        $this->assertSame('Ouvrir · 2', $tile->filter('[data-testid="pack-open"]')->text(normalizeWhitespace: true));
    }

    /**
     * A booster's variant inside its universe tile (« + » in the corner, « Ouvrir » in the foot).
     */
    private function packTile(Crawler $crawler, \App\Entity\Booster $booster): Crawler
    {
        $tile = $crawler->filter('[data-testid="booster-card"][data-booster-id="' . $booster->getId() . '"]');
        $this->assertCount(1, $tile, 'Pack tile not found.');

        return $tile;
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

    public function testThePackFilterKeepsThePacksOnSaleOrTheIncompleteUniverses(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $all = $component->render()->crawler()->filter('[data-testid="booster-card"]')->count();
        // nothing on sale: no « Payants » filter at all
        $this->assertCount(0, $component->render()->crawler()->filter('[data-testid="pack-filter-payants"]'));

        $booster->setPurchasable(true)->setPurchasePrice(10);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('filterPacks', ['filter' => 'payants']);

        $this->assertSame('payants', $component->component()->filter);
        $crawler = $component->render()->crawler();
        $this->assertSame('true', $crawler->filter('[data-testid="pack-filter-payants"]')->attr('aria-pressed'));
        $this->assertStringContainsString('Payants · 1', $crawler->filter('[data-testid="pack-filter-payants"]')->text());
        $this->assertCount(1, $crawler->filter('[data-testid="boosters-grid"] [data-testid="booster-card"]'));
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

        $crawler = $client->request('GET', '/boosters?filtre=payants');

        self::assertResponseIsSuccessful();
        // nothing on sale in the fixtures: the paid filter is empty and says so
        $this->assertCount(0, $crawler->filter('[data-testid="booster-card"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="pack-filter-empty"]'));
        $this->assertStringContainsString('Aucun pack payant', $crawler->filter('[data-testid="pack-filter-empty"]')->text());

        // the retired « En stock » filter falls back to every pack
        $crawler = $client->request('GET', '/boosters?filtre=stock');
        $this->assertSame('true', $crawler->filter('[data-testid="pack-filter-tous"]')->attr('aria-pressed'));
    }

    public function testTheCompletionSortPutsTheUnfinishedUniversesFirstAndAzIsReadFromTheUrl(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $suggested = array_map(static fn (array $group): ?array => $group['completion'], $component->component()->getPackGroups());
        // completion (default): every unfinished universe before the complete ones, closest to completion first
        $this->assertSame('true', $component->render()->crawler()->filter('[data-testid="pack-sort-completion"]')->attr('aria-pressed'));
        $seenComplete = false;
        $previous = 101;
        foreach ($suggested as $completion) {
            $percentage = $completion['percentage'] ?? -1;
            if (100 === $percentage) {
                $seenComplete = true;
                continue;
            }
            $this->assertFalse($seenComplete, 'An unfinished universe comes after a complete one.');
            $this->assertLessThanOrEqual($previous, $percentage);
            $previous = $percentage;
        }

        $crawler = $client->request('GET', '/boosters?tri=az');
        self::assertResponseIsSuccessful();
        $this->assertSame('true', $crawler->filter('[data-testid="pack-sort-az"]')->attr('aria-pressed'));
        $this->assertSame('false', $crawler->filter('[data-testid="pack-sort-completion"]')->attr('aria-pressed'));
        $names = $crawler->filter('[data-testid="universe-tile"]')->each(
            static fn (Crawler $tile): string => (string) $tile->attr('data-universe'),
        );
        $sorted = $names;
        natcasesort($sorted);
        $this->assertSame(array_values($sorted), $names);
    }

    public function testVariantsOfAUniverseShareOneTileAndThePickedOneStaysSelected(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $standard = $this->firstPublishedBooster();
        $boosted = new \App\Entity\Booster()
            ->setExtension($standard->getExtension())
            ->setName($standard->getExtension()->getName() . ' - Boosted Drops')
            ->setClaimable(false)
            ->setPurchasable(true)
            ->setPurchasePrice(500)
            ->setRarityRates([['rarities' => ['rare' => 100], 'holoChance' => 100]])
        ;
        $boosted->setImageName('default_card.png');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($boosted);
        $entityManager->flush();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $tile = $this->universeTile($component->render()->crawler(), $standard);
        $this->assertCount(2, $tile->filter('[data-testid="booster-card"]'));
        // the free pack is shown first, the Boosted one waits behind its tab
        $this->assertNull($this->packTile($tile, $standard)->attr('hidden'));
        $this->assertNotNull($this->packTile($tile, $boosted)->attr('hidden'));

        $component->call('pickVariant', ['extensionId' => (string) $standard->getExtension()->getId(), 'boosterId' => (string) $boosted->getId()]);
        // a later re-render (a claim, another filter) keeps the picked tab
        $component->call('filterPacks', ['filter' => 'tous']);

        $tile = $this->universeTile($component->render()->crawler(), $standard);
        $picked = $this->packTile($tile, $boosted);
        $this->assertNull($picked->attr('hidden'));
        $this->assertNotNull($this->packTile($tile, $standard)->attr('hidden'));
        // paid variant: the corner « + » is the yellow buy button
        $this->assertStringContainsString('pack-tile__plus--coin', (string) $picked->filter('[data-testid="shop-buy"]')->attr('class'));
        // its odds stay behind « Taux », like every other pack
        $this->assertCount(1, $picked->filter('[data-testid="drop-rates-toggle"]'));

        // a forged pick from another universe is ignored
        $component->call('pickVariant', ['extensionId' => 'nope', 'boosterId' => (string) $boosted->getId()]);
        $this->assertArrayNotHasKey('nope', $component->component()->variants);
    }

    /**
     * The universe tile holding a booster (all its variants inside).
     */
    private function universeTile(Crawler $crawler, \App\Entity\Booster $booster): Crawler
    {
        $tile = $crawler->filter('[data-testid="universe-tile"]')->reduce(
            static fn (Crawler $universe): bool => $universe->filter('[data-booster-id="' . $booster->getId() . '"]')->count() > 0,
        );
        $this->assertCount(1, $tile, 'Universe tile not found.');

        return $tile;
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
