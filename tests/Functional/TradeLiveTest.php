<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Tests\FeatureFlagTrait;
use App\Twig\Components\TradeInbox;
use App\Twig\Components\TradesNavLink;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Live side of /echanges: realtime re-renders, the header badge, and the
 * paginated history with its masking rule.
 */
final class TradeLiveTest extends WebTestCase
{
    use FeatureFlagTrait;
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string BARLITO = '188967649332428800';

    private const string JUJU = '195659530363731968';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Extension $extension;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->extension = new Extension()
            ->setName('Trade live extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);
        $this->entityManager->flush();
    }

    // -------------------------------------------------------------- live

    public function testTheInboxReRendersOnTheRealtimeEvent(): void
    {
        $this->authenticateClient($this->client, self::JUJU);

        $html = (string) $this->createLiveComponent(TradeInbox::class, client: $this->client)->render();

        $this->assertStringContainsString(
            'live-updates:trades-changed@window->live#$render',
            (string) new Crawler($html)->filter('[data-live-name-value="TradeInbox"]')->attr('data-action'),
        );
    }

    public function testTheInboxPicksUpAnOfferReceivedMeanwhile(): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $this->assertSame(0, new Crawler((string) $component->render())->filter('[data-testid="received-offer"]')->count());

        $this->resolvedOffer($this->user(self::BARLITO), $juju, TradeOfferStatusEnum::PENDING);

        // what the realtime event triggers: a plain re-render
        $this->assertSame(1, new Crawler((string) $component->refresh()->render())->filter('[data-testid="received-offer"]')->count());
    }

    public function testAnInboxActionEchoesALocalEventForTheHeaderBadge(): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offer = $this->resolvedOffer($this->user(self::BARLITO), $juju, TradeOfferStatusEnum::PENDING);

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $component->call('refuse', ['offerId' => (string) $offer->getId()]);

        $this->assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'trades:changed'));
    }

    public function testTheHeaderBadgeIsALiveComponent(): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);

        $badge = $this->createLiveComponent(TradesNavLink::class, client: $this->client);
        $this->assertSame(0, new Crawler((string) $badge->render())->filter('[data-testid="pending-trades-badge"]')->count());

        $this->resolvedOffer($this->user(self::BARLITO), $juju, TradeOfferStatusEnum::PENDING);
        $this->resolvedOffer($this->user(self::BARLITO), $juju, TradeOfferStatusEnum::PENDING);

        $count = new Crawler((string) $badge->refresh()->render())->filter('[data-testid="pending-trades-badge"]');
        $this->assertSame('2', trim($count->text()));

        $crawler = $this->client->request('GET', '/');
        $link = $crawler->filter('[data-testid="nav-trades"]');
        $this->assertCount(1, $link);
        $this->assertSame('2', trim($link->filter('[data-testid="pending-trades-badge"]')->text()));
        $this->assertStringContainsString('live-updates:trades-changed@window->live#$render', (string) $link->attr('data-action'));
        $this->assertStringContainsString('trades:changed@window->live#$render', (string) $link->attr('data-action'));
    }

    public function testTheHeaderBadgeStaysEmptyWhileTradesAreOff(): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $this->resolvedOffer($this->user(self::BARLITO), $juju, TradeOfferStatusEnum::PENDING);
        $this->setFeature(FeatureEnum::TRADES, false);

        $html = (string) $this->createLiveComponent(TradesNavLink::class, client: $this->client)->render();

        $this->assertSame(0, new Crawler($html)->filter('[data-testid="pending-trades-badge"]')->count());
    }

    // ----------------------------------------------------------- history

    public function testTheHistoryIsPaginated(): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $barlito = $this->user(self::BARLITO);
        for ($i = 0; $i < TradeInbox::HISTORY_PER_PAGE + 5; ++$i) {
            $this->resolvedOffer($barlito, $juju, TradeOfferStatusEnum::REFUSED, new \DateTimeImmutable(\sprintf('2026-09-01 +%d minutes', $i)));
        }

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $firstPage = new Crawler((string) $component->render());

        $this->assertCount(TradeInbox::HISTORY_PER_PAGE, $firstPage->filter('[data-testid="history-entry"]'));
        $this->assertSame('Page 1 / 2', trim($firstPage->filter('[data-testid="history-page"]')->text()));
        $this->assertNotNull($firstPage->filter('[data-testid="history-prev"]')->attr('disabled'));

        $component->call('goToHistoryPage', ['page' => 2]);
        $secondPage = new Crawler((string) $component->render());

        $this->assertCount(5, $secondPage->filter('[data-testid="history-entry"]'));
        $this->assertNull($secondPage->filter('[data-testid="history-prev"]')->attr('disabled'));
        $this->assertNotNull($secondPage->filter('[data-testid="history-next"]')->attr('disabled'));

        // a page past the end is clamped to the last one
        $component->call('goToHistoryPage', ['page' => 99]);
        $this->assertCount(5, new Crawler((string) $component->render())->filter('[data-testid="history-entry"]'));
    }

    public function testAnAcceptedTradeShowsBothSidesInClear(): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $given = $this->card('Donnée par Juju');
        $received = $this->card('Reçue par Juju');
        // the requested card is Juju's: Juju gives it, receives the offered one
        $this->resolvedOffer($this->user(self::BARLITO), $juju, TradeOfferStatusEnum::ACCEPTED, offered: $received, requested: $given);

        $entry = $this->historyEntry();

        $this->assertStringContainsString('Tu as donné', $entry->text());
        $this->assertStringContainsString('Tu as reçu', $entry->text());
        $this->assertStringContainsString($given->getName(), $entry->html());
        $this->assertStringContainsString($received->getName(), $entry->html());
        $this->assertCount(0, $entry->filter('[data-testid="masked-card"]'));
    }

    /**
     * @return iterable<string, array{TradeOfferStatusEnum}>
     */
    public static function unresolvedOutcomeProvider(): iterable
    {
        yield 'refusée' => [TradeOfferStatusEnum::REFUSED];
        yield 'annulée' => [TradeOfferStatusEnum::CANCELLED];
        yield 'invalidée' => [TradeOfferStatusEnum::INVALIDATED];
    }

    #[DataProvider('unresolvedOutcomeProvider')]
    public function testAnOfferThatMovedNothingNeverRevealsAnUnknownCard(TradeOfferStatusEnum $status): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $mine = $this->card('La mienne', CardRarityEnum::COMMON);
        $this->give($juju, $mine, 1);
        $unknown = $this->card('Jamais vue', CardRarityEnum::LEGENDARY);
        $this->resolvedOffer($this->user(self::BARLITO), $juju, $status, offered: $unknown, requested: $mine);

        $html = (string) $this->createLiveComponent(TradeInbox::class, client: $this->client)->render();
        $entry = new Crawler($html)->filter('[data-testid="history-entry"]');

        $this->assertStringContainsString('Tu aurais reçu', $entry->text());
        $this->assertStringContainsString($mine->getName(), $entry->html(), 'Une carte possédée reste en clair.');
        $masked = $entry->filter('[data-testid="masked-card"]');
        $this->assertCount(1, $masked);
        $this->assertSame('legendary', $masked->attr('data-rarity'));
        $this->assertStringNotContainsString($unknown->getName(), $html);
        $this->assertStringNotContainsString((string) $unknown->getId(), $html);
    }

    public function testACardOnceDrawnStaysVisibleEvenAfterItLeftTheCollection(): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $known = $this->card('Déjà tirée');
        $this->drawn($juju, $known);
        $this->resolvedOffer($this->user(self::BARLITO), $juju, TradeOfferStatusEnum::REFUSED, offered: $known);

        $this->assertStringContainsString($known->getName(), $this->historyEntry()->html());
    }

    public function testAnEmptyInventoryRowDoesNotCountAsOwnership(): void
    {
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $unknown = $this->card('Ligne à zéro');
        // an aborted acceptance may leave such a row behind: it proves nothing
        $this->give($juju, $unknown, 0);
        $this->resolvedOffer($this->user(self::BARLITO), $juju, TradeOfferStatusEnum::CANCELLED, offered: $unknown);

        $html = (string) $this->createLiveComponent(TradeInbox::class, client: $this->client)->render();

        $this->assertStringNotContainsString($unknown->getName(), $html);
    }

    // ----------------------------------------------------------- utilities

    private function historyEntry(): Crawler
    {
        $html = (string) $this->createLiveComponent(TradeInbox::class, client: $this->client)->render();
        $entries = new Crawler($html)->filter('[data-testid="history-entry"]');
        $this->assertCount(1, $entries);

        return $entries;
    }

    private function user(string $discordId): DiscordUser
    {
        $user = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);
        \assert($user instanceof DiscordUser);

        return $user;
    }

    private function card(string $name, CardRarityEnum $rarity = CardRarityEnum::COMMON): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity($rarity)
        ;
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function give(DiscordUser $user, Card $card, int $quantity): void
    {
        $this->entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($quantity)->setHoloQuantity(0));
        $this->entityManager->flush();
    }

    private function drawn(DiscordUser $user, Card $card): void
    {
        $booster = new Booster()
            ->setExtension($this->extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $this->entityManager->persist($booster);
        $opening = new BoosterOpening($user, $booster, 42, new \DateTimeImmutable());
        $this->entityManager->persist($opening);
        $openingCard = new BoosterOpeningCard($opening, $card, 1, 0);
        $opening->addBoosterOpeningCard($openingCard);
        $this->entityManager->persist($openingCard);
        $this->entityManager->flush();
    }

    /**
     * Stored as is (no service): the history only reads what was recorded.
     */
    private function resolvedOffer(
        DiscordUser $proposer,
        DiscordUser $receiver,
        TradeOfferStatusEnum $status,
        ?\DateTimeImmutable $resolvedAt = null,
        ?Card $offered = null,
        ?Card $requested = null,
    ): TradeOffer {
        $offered ??= $this->card('Offerte');
        if (!$status->isFinal()) {
            // a pending offer the proposer cannot cover is swept at display time
            $this->give($proposer, $offered, 1);
        }

        $offer = new TradeOffer()->setProposer($proposer)->setReceiver($receiver);
        $offer->addLine(new TradeOfferLine()->setSide(TradeOfferSideEnum::OFFERED)->setCard($offered)->setNormalQuantity(1));
        $offer->addLine(new TradeOfferLine()->setSide(TradeOfferSideEnum::REQUESTED)->setCard($requested ?? $this->card('Demandée'))->setNormalQuantity(1));
        if ($status->isFinal()) {
            $offer->resolve($status, $resolvedAt ?? new \DateTimeImmutable());
        }

        $this->entityManager->persist($offer);
        $this->entityManager->flush();

        return $offer;
    }
}
