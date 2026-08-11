<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
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

        $rendered = (string) $component->render();
        // Empty-inventory user → every drawn card is new.
        $this->assertStringContainsString('NOUVEAU', $rendered);

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

        $crawler = new Crawler((string) $component->render());
        $tiles = $crawler->filter('.opening__card-tile');
        $this->assertGreaterThan(0, $tiles->count());

        // Empty inventory, nothing opened yet: every tile is masked and carries
        // no name, rarity, artwork url or unmask payload in the DOM.
        $tiles->each(function (Crawler $tile): void {
            $this->assertStringContainsString('is-masked', (string) $tile->attr('class'));
            $this->assertSame('', (string) $tile->attr('data-name'));
            $this->assertSame('', (string) $tile->attr('data-rarity'));
            $this->assertNull($tile->attr('data-card-id'));
            $this->assertSame('Non révélée', trim($tile->filter('.opening__card-name')->text()));
            $this->assertCount(0, $tile->filter('img'));
        });
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
            // masked presentation…
            $this->assertSame('', (string) $tile->attr('data-name'));
            $this->assertSame('Non révélée', trim($tile->filter('.opening__card-name')->text()));
            // …but the unmask payload is on board for the flip
            $this->assertContains((string) $tile->attr('data-card-id'), $newCardIds);
            $this->assertNotSame('', (string) $tile->attr('data-pending-name'));
            $this->assertNotSame('', (string) $tile->attr('data-pending-rarity'));
            // the whole rendered card is hidden: the component carries several
            // <img> (front face + card back), the wrapper is the reveal hook
            $this->assertNotNull($tile->filter('.opening__card-render')->attr('hidden'), 'Pending artwork must stay hidden until the flip.');
        });
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
