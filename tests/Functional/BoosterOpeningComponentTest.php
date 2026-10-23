<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Repository\BoosterRepository;
use App\Service\Booster\BoosterClaimService;
use App\Twig\Components\BoosterOpening;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BoosterOpeningComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string USER_WITHOUT_INVENTORY = '195659530363731968';

    public function testOpenDrawsCardsDebitsInventoryAndFlagsNewCards(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $component->call('open');

        $opening = $component->component();
        $this->assertNull($opening->error);
        $this->assertNotNull($opening->opening);

        $rendered = new Crawler((string) $component->render());
        // Empty-inventory user → every drawn card is new.
        $this->assertGreaterThan(0, $rendered->filter('[data-testid="recap-new"]')->count());
        $this->assertCount(0, $rendered->filter('.opening__recap-grid [data-state="duplicate"]')->reduce(
            static fn (Crawler $cell): bool => !str_contains($cell->text(), 'doublon') && !str_contains($cell->text(), 'DOUBLON'),
        ));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $userBooster = $entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $user, 'booster' => $booster]);
        $this->assertNotNull($userBooster);
        $this->assertSame(0, $userBooster->getQuantity());

        $userCards = $entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $user]);
        $totalCards = array_sum(array_map(static fn (UserCard $userCard): int => $userCard->getQuantity(), $userCards));
        $this->assertSame($booster->getCardCount(), $totalCards);
    }

    public function testSetContentsMasksUnownedCardsWithoutLeakingThem(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );

        $rendered = (string) $component->render();
        $crawler = new Crawler($rendered);
        $tiles = $crawler->filter('.opening__card-tile');
        $this->assertGreaterThan(0, $tiles->count());

        // Empty inventory, nothing opened yet: every tile is a card back with its
        // rarity only — no name, artwork, id or unmask payload in the DOM.
        $tiles->each(function (Crawler $tile): void {
            $this->assertStringContainsString('is-masked', (string) $tile->attr('class'));
            $this->assertSame('', (string) $tile->attr('data-name'));
            $this->assertNull($tile->attr('data-card-id'));
            $this->assertNull($tile->attr('data-pending-name'));
            $this->assertSame('Carte à trouver', trim($tile->filter('.cm')->text()));
            $this->assertCount(1, $tile->filter('[data-testid="masked-card"][data-rarity]'));
            $this->assertCount(0, $tile->filter('img, .card'));
        });
        $this->assertSame('0 / ' . $tiles->count() . ' trouvées', trim($crawler->filter('[data-testid="set-found"]')->text()));

        foreach ($component->component()->getExtensionCatalog() as $card) {
            $this->assertStringNotContainsString((string) $card->getId(), $rendered);
            $this->assertStringNotContainsString(htmlspecialchars($card->getName()), $rendered, 'An unowned card name leaked into the page.');
        }
    }

    public function testOwnedCardsAreInClearAndSearchableMissingOnesAreNot(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $catalog = $component->component()->getExtensionCatalog();
        $owned = $catalog[0];
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserCard()
            ->setDiscordUser($entityManager->find(DiscordUser::class, $user->getDiscordId()))
            ->setCard($entityManager->find(Card::class, $owned->getId()))
            ->setQuantity(1));
        $entityManager->flush();

        $rendered = (string) $component->refresh()->render();
        $crawler = new Crawler($rendered);

        $clear = $crawler->filter('.opening__card-tile:not(.is-masked)');
        $this->assertCount(1, $clear);
        // the search matches data-name: only the owned card carries one
        $this->assertSame(mb_strtolower($owned->getName()), $clear->attr('data-name'));
        $this->assertCount(\count($catalog) - 1, $crawler->filter('.opening__card-tile.is-masked[data-name=""]'));
        $this->assertSame('1 / ' . \count($catalog) . ' trouvées', trim($crawler->filter('[data-testid="set-found"]')->text()));

        foreach (\array_slice($catalog, 1) as $card) {
            $this->assertStringNotContainsString((string) $card->getId(), $rendered);
        }
    }

    public function testPackRatesAverageTheSlots(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $rates = $component->component()->getPackRates();

        $slots = $booster->getDropRates();
        foreach ($rates['rarities'] as $rarity => $rate) {
            $expected = round(array_sum(array_map(static fn (array $slot): float => $slot['rates'][$rarity] ?? 0.0, $slots)) / \count($slots), 1);
            $this->assertSame($expected, $rate);
        }
        $this->assertEqualsWithDelta(100.0, array_sum($rates['rarities']), 0.5);
        $this->assertSame(round(array_sum(array_column($slots, 'holoChance')) / \count($slots), 1), $rates['holo']);
        $this->assertCount(1, new Crawler((string) $component->render())->filter('[data-testid="pack-rates"]'));
    }

    public function testRecapSummarisesTheDrawWithoutShowingItBeforeTheReveal(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );

        // nothing drawn yet: no summary at all
        $this->assertCount(0, new Crawler((string) $component->render())->filter('[data-testid="opening-recap"]'));

        $component->call('open');
        $opening = $component->component();
        $crawler = new Crawler((string) $component->render());
        $summary = $crawler->filter('[data-testid="opening-recap"]');
        $this->assertCount(1, $summary);
        // rendered with the draw, but hidden: the reveal controller shows it once every card was flipped
        $this->assertNotNull($summary->attr('hidden'));

        $total = $crawler->filter('.opening__card-tile')->count();
        $newCount = \count(array_unique($opening->newCardIds));
        // floored like /univers and the hub: a universe never shows 100 % before it is complete
        $percentage = (int) floor($newCount / $total * 100);
        $this->assertCount($booster->getCardCount(), $summary->filter('[data-testid="recap-cards"] [data-testid="card-cell"]'));
        $this->assertCount($newCount, $summary->filter('[data-testid="recap-new"]'));
        $this->assertStringContainsString($booster->getCardCount() . ' cartes', $summary->filter('h1')->text());
        $this->assertStringContainsString('passe de 0 % à ' . $percentage . ' %', $summary->filter('[data-testid="recap-lead"]')->text());
        $this->assertStringContainsString($newCount . ' / ' . $total, $summary->filter('[data-testid="recap-completion"]')->text());
        $this->assertStringContainsString('Ouvrir le suivant · 1 restant', $summary->filter('[data-testid="open-another"]')->text());

        // best find = a card of the rarest drawn rarity, highlighted once
        $reveal = $opening->getRevealCards();
        $rarest = max(array_map(static fn (array $slot): int => $slot['card']->getRarity()->rank(), $reveal));
        $bestNames = array_map(
            static fn (array $slot): string => $slot['card']->getName(),
            array_filter($reveal, static fn (array $slot): bool => $slot['card']->getRarity()->rank() === $rarest),
        );
        $bestText = $summary->filter('[data-testid="recap-best"]')->text();
        $this->assertNotEmpty(array_filter($bestNames, static fn (string $name): bool => str_starts_with($bestText, $name)));
        $this->assertCount(1, $summary->filter('.opening__recap-best-cell'));

        // the second draw starts from the first one's cards
        $component->call('open');
        $second = new Crawler((string) $component->render());
        $this->assertStringContainsString(
            'Ta complétion ' . $booster->getExtension()->getName() . ' ' . ($percentage === (int) floor(($newCount + \count(array_unique($component->component()->newCardIds))) / $total * 100) ? 'reste à' : 'passe de ' . $percentage . ' %'),
            $second->filter('[data-testid="recap-lead"]')->text(),
        );
        $this->assertCount(0, $second->filter('[data-testid="open-another"]'), 'No pack left: no « Ouvrir le suivant ».');
    }

    public function testFreshlyDrawnCardsStayMaskedAsPendingUntilTheFlip(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $component->call('open');

        $crawler = new Crawler((string) $component->render());

        // Empty inventory before the opening: every drawn card is new, so the set
        // list must not unmask a single tile server-side — the reveal controller
        // promotes the is-pending tiles flip by flip.
        $this->assertCount(0, $crawler->filter('.opening__card-tile:not(.is-masked)'));

        $pending = $crawler->filter('.opening__card-tile.is-pending');
        $newCardIds = $component->component()->newCardIds;
        $this->assertCount(\count($newCardIds), $pending);

        $pending->each(function (Crawler $tile) use ($newCardIds): void {
            // masked presentation, like any card not owned before the draw…
            $this->assertSame('', (string) $tile->attr('data-name'));
            $this->assertSame('Carte à trouver', trim($tile->filter('[data-opening-masked] .cm')->text()));
            // …but the unmask payload is on board for the flip
            $this->assertContains((string) $tile->attr('data-card-id'), $newCardIds);
            $this->assertNotSame('', (string) $tile->attr('data-pending-name'));
            // the real cell is hidden until the reveal controller swaps it in
            $this->assertNotNull($tile->filter('[data-opening-reveal]')->attr('hidden'), 'Pending artwork must stay hidden until the flip.');
        });
        // the counter still says what the player owned before the draw
        $this->assertSame('0 / ' . $crawler->filter('.opening__card-tile')->count() . ' trouvées', trim($crawler->filter('[data-testid="set-found"]')->text()));
    }

    public function testRevealRendersCardsOrderedRarestLast(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $component->call('open');

        $rendered = (string) $component->render();
        // 3D pack is wired into the page.
        $this->assertStringContainsString('pack-opening-3d', $rendered);
        $this->assertStringContainsString('/models/pack-wide.gltf', $rendered);

        $crawler = new Crawler($rendered);
        $rarities = $crawler->filter('.opening__reveal-card')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-rarity'),
        );

        $this->assertCount($booster->getCardCount(), $rarities);

        $rank = array_flip(array_map(
            static fn (CardRarityEnum $rarity): string => $rarity->value,
            CardRarityEnum::ascending(),
        ));
        $ranks = array_map(static fn (string $rarity): int => $rank[$rarity], $rarities);
        $sortedRanks = $ranks;
        sort($sortedRanks);

        $this->assertSame($sortedRanks, $ranks, 'Reveal cards must be ordered from common to rarest (climax last).');

        // the revealed rarity is never told by its colour alone
        $crawler->filter('.opening__reveal-card')->each(function (Crawler $node): void {
            $rarity = CardRarityEnum::from((string) $node->attr('data-rarity'));
            $this->assertStringStartsWith($rarity->icon() . ' ' . $rarity->label(), (string) $node->attr('data-label'));
        });
    }

    public function testRevealStampsNewCardsOnTheirFirstCopyOnly(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );

        $component->call('open');
        $this->assertStampsMatchNewCards($component->component()->newCardIds, (string) $component->render());

        // the second draw starts from the first one's cards: those are no longer new
        $firstCardIds = array_column($component->component()->revealOrder, 'cardId');
        $component->call('open');
        $newCardIds = $component->component()->newCardIds;
        $this->assertSame([], array_values(array_intersect($newCardIds, $firstCardIds)));
        $this->assertStampsMatchNewCards($newCardIds, (string) $component->render());
    }

    public function testOpeningWithoutInventoryReportsAnError(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $component->call('open');

        $opening = $component->component();
        $this->assertNull($opening->opening);
        // The player-facing message is French, not the technical exception message.
        $this->assertSame('Tu ne possèdes pas ce booster.', $opening->error);
    }

    public function testOpeningAnotherPackDrawsInOneClick(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );

        $component->call('open');
        $first = $component->component()->opening;
        $this->assertNotNull($first, 'first open');
        $firstId = (string) $first->getId();

        // « Ouvrir un autre » appelle open directement : plus de passage par
        // l'état scellé, donc un seul clic par pack
        $component->call('open');
        $second = $component->component();
        $this->assertNull($second->error, 'second open must not error');
        $this->assertNotNull($second->opening, 'second open');
        $this->assertNotSame($firstId, (string) $second->opening->getId(), 'a second draw, not the first one again');

        // c'est cet id que le contrôleur JS surveille pour ré-armer le pack :
        // le nombre de cartes, lui, est identique d'un pack à l'autre
        $rendered = new Crawler((string) $component->render());
        $this->assertSame(
            (string) $second->opening->getId(),
            $rendered->filter('.opening')->attr('data-booster-opening-opening-id-value'),
        );
    }

    public function testResetClearsTheTable(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );

        $component->call('open');
        $this->assertNotNull($component->component()->opening);

        $component->call('reset');
        $this->assertNull($component->component()->opening, 'reset clears the opening');
        $this->assertSame(
            '',
            new Crawler((string) $component->render())->filter('.opening')->attr('data-booster-opening-opening-id-value'),
        );
    }

    /**
     * @param list<string> $newCardIds
     */
    private function assertStampsMatchNewCards(array $newCardIds, string $rendered): void
    {
        $stampedIds = new Crawler($rendered)->filter('.opening__reveal-card')->each(
            static fn (Crawler $card): ?string => $card->filter('[data-new-tag]')->count() > 0 ? (string) $card->attr('data-card-id') : null,
        );
        $stampedIds = array_values(array_filter($stampedIds, static fn (?string $id): bool => null !== $id));

        $expected = array_values(array_unique($newCardIds));
        sort($expected);
        sort($stampedIds);
        $this->assertSame($expected, $stampedIds, 'One stamp per new card, on its first copy only.');
        // played by the reveal controller after the flip, never pre-armed server-side
        $this->assertStringNotContainsString('new-tag is-on', $rendered);
    }

    private function claim(DiscordUser $user, Booster $booster): void
    {
        static::getContainer()->get(BoosterClaimService::class)->claim($user, $booster);
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
