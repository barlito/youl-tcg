<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\TradeLineRequest;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Service\Trade\TradeOfferService;
use App\Twig\Components\TradeInbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class TradeRevealTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string BARLITO = '188967649332428800';

    private const string JUJU = '195659530363731968';

    private const string FARPH = '188967949963362304';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Extension $extension;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->extension = new Extension()
            ->setName('Trade reveal extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);
        $this->entityManager->flush();
    }

    public function testAcceptanceStoresWhetherEachTakerOwnedTheCardBefore(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->user(self::JUJU);
        $known = $this->card('Déjà chez Juju', CardRarityEnum::COMMON);
        $unknown = $this->card('Inconnue de Juju', CardRarityEnum::RARE);
        $requested = $this->card('Demandée', CardRarityEnum::COMMON);
        $this->give($barlito, $known, 1);
        $this->give($juju, $known, 1);
        $this->give($barlito, $unknown, 1);
        $this->give($juju, $requested, 1);
        $offer = $this->offer($barlito, $juju, [$known, $unknown], [$requested]);

        static::getContainer()->get(TradeOfferService::class)->accept($offer, $juju);

        $expected = [$known->getName() => false, $unknown->getName() => true, $requested->getName() => true];
        ksort($expected);
        $this->assertSame($expected, $this->newFlagsByCard($offer));
    }

    public function testAcceptingRevealsTheReceivedCardsRarestLastWithTheNewStamp(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $known = $this->card('Commune connue', CardRarityEnum::COMMON);
        $legendary = $this->card('Légendaire nouvelle', CardRarityEnum::LEGENDARY);
        $uncommon = $this->card('Peu commune nouvelle', CardRarityEnum::UNCOMMON);
        $requested = $this->card('Demandée', CardRarityEnum::COMMON);
        $this->give($barlito, $legendary, 1);
        $this->give($barlito, $known, 1);
        $this->give($barlito, $uncommon, 1);
        $this->give($juju, $known, 2);
        $this->give($juju, $requested, 1);
        $offer = $this->offer($barlito, $juju, [$legendary, $known, $uncommon], [$requested]);

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $this->assertSame(0, new Crawler((string) $component->render())->filter('[data-testid="trade-reveal"]')->count(), 'Nothing to reveal before the acceptance.');

        $component->call('accept', ['offerId' => (string) $offer->getId()]);
        $crawler = new Crawler((string) $component->render());

        $cards = $crawler->filter('[data-testid="trade-reveal"] [data-testid="trade-reveal-card"]');
        $this->assertSame(['common', 'uncommon', 'legendary'], $cards->each(static fn (Crawler $card): string => (string) $card->attr('data-rarity')));
        $this->assertSame(
            [false, true, true],
            $cards->each(static fn (Crawler $card): bool => $card->filter('[data-new-tag]')->count() > 0),
        );
        $this->assertStringContainsString($legendary->getName(), $cards->last()->text());
        // only what Juju RECEIVES is revealed, never what she gave
        $this->assertStringNotContainsString($requested->getName(), $crawler->filter('[data-testid="trade-reveal"]')->text());

        $entry = $crawler->filter('#history-' . $offer->getId());
        $this->assertNotNull($entry->attr('open'), 'The fresh acceptance opens its history entry.');

        $component->call('closeReveal');
        $this->assertSame(0, new Crawler((string) $component->render())->filter('[data-testid="trade-reveal"]')->count());
    }

    public function testTheProposerNotificationLinkOpensTheEntryAndRevealsWhatTheyReceived(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $offered = $this->card('Offerte', CardRarityEnum::COMMON);
        $requested = $this->card('Reçue par Barlito', CardRarityEnum::RARE);
        $this->give($barlito, $offered, 1);
        $this->give($juju, $requested, 1);
        $offer = $this->offer($barlito, $juju, [$offered], [$requested]);
        static::getContainer()->get(TradeOfferService::class)->accept($offer, $juju);

        $crawler = $this->client->request('GET', '/echanges?offre=' . $offer->getId());

        $this->assertResponseIsSuccessful();
        $this->assertNotNull($crawler->filter('#history-' . $offer->getId())->attr('open'));
        $this->assertCount(1, $crawler->filter('details[open]'));
        $reveal = $crawler->filter('[data-testid="trade-reveal"]');
        $this->assertCount(1, $reveal->filter('[data-testid="trade-reveal-card"]'));
        $this->assertStringContainsString($requested->getName(), $reveal->text());
        $this->assertStringContainsString('avec Juju', $reveal->text());
        $this->assertCount(1, $reveal->filter('[data-new-tag]'));
    }

    public function testTheLinkOpensAnOfferBeyondTheFirstHistoryPage(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $service = static::getContainer()->get(TradeOfferService::class);
        $offered = $this->card('Offerte', CardRarityEnum::COMMON);
        $requested = $this->card('Ancienne', CardRarityEnum::COMMON);
        $this->give($barlito, $offered, TradeInbox::HISTORY_PER_PAGE + 1);
        $this->give($juju, $requested, TradeInbox::HISTORY_PER_PAGE + 1);
        $target = $this->offer($barlito, $juju, [$offered], [$requested]);
        $service->accept($target, $juju);

        // a full page of newer entries pushes it to page 2 at least
        foreach (range(1, TradeInbox::HISTORY_PER_PAGE) as $ignored) {
            $service->cancel($this->offer($barlito, $juju, [$offered], [$requested]), $barlito);
        }

        $crawler = $this->client->request('GET', '/echanges?offre=' . $target->getId());

        $this->assertResponseIsSuccessful();
        $this->assertNotNull($crawler->filter('#history-' . $target->getId())->attr('open'));
        $this->assertDoesNotMatchRegularExpression('/Page 1 \//', $crawler->filter('[data-testid="history-page"]')->text());
        $this->assertCount(1, $crawler->filter('[data-testid="trade-reveal"]'));
    }

    public function testAForeignUnknownOrPendingOfferIsIgnoredSilently(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->user(self::JUJU);
        $offered = $this->card('Offerte', CardRarityEnum::COMMON);
        $requested = $this->card('Demandée', CardRarityEnum::COMMON);
        $this->give($barlito, $offered, 2);
        $this->give($juju, $requested, 2);
        $accepted = $this->offer($barlito, $juju, [$offered], [$requested]);
        static::getContainer()->get(TradeOfferService::class)->accept($accepted, $juju);
        $pending = $this->offer($barlito, $juju, [$offered], [$requested]);

        $this->authenticateClient($this->client, self::FARPH);
        foreach ([(string) $accepted->getId(), '019a0000-0000-7000-8000-000000000000', 'pas-un-uuid'] as $offerId) {
            $html = (string) $this->client->request('GET', '/echanges?offre=' . $offerId)->html();
            $this->assertResponseIsSuccessful();
            $this->assertStringNotContainsString('data-testid="trade-reveal"', $html, $offerId);
            $this->assertStringNotContainsString($offered->getName(), $html, $offerId);
            $this->assertStringNotContainsString('data-focused', $html, $offerId);
        }

        // the proposer's own PENDING offer is not a history entry: nothing to open
        $this->authenticateClient($this->client, self::BARLITO);
        $html = (string) $this->client->request('GET', '/echanges?offre=' . $pending->getId())->html();
        $this->assertStringNotContainsString('data-focused', $html);
    }

    public function testARefusedOfferOpensItsEntryWithoutReveal(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $offered = $this->card('Offerte', CardRarityEnum::COMMON);
        $requested = $this->card('Demandée', CardRarityEnum::COMMON);
        $this->give($barlito, $offered, 1);
        $this->give($juju, $requested, 1);
        $offer = $this->offer($barlito, $juju, [$offered], [$requested]);
        static::getContainer()->get(TradeOfferService::class)->refuse($offer, $juju);

        $crawler = $this->client->request('GET', '/echanges?offre=' . $offer->getId());

        $this->assertNotNull($crawler->filter('#history-' . $offer->getId())->attr('open'));
        $this->assertCount(0, $crawler->filter('[data-testid="trade-reveal"]'));
        // the history masking rule still applies to a refused offer
        $this->assertStringNotContainsString($requested->getName(), $crawler->html());
    }

    public function testAPlainVisitStaysClosed(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $offered = $this->card('Offerte', CardRarityEnum::COMMON);
        $requested = $this->card('Demandée', CardRarityEnum::COMMON);
        $this->give($barlito, $offered, 1);
        $this->give($juju, $requested, 1);
        static::getContainer()->get(TradeOfferService::class)->accept($this->offer($barlito, $juju, [$offered], [$requested]), $juju);

        $crawler = $this->client->request('GET', '/echanges');

        $this->assertCount(0, $crawler->filter('details[open]'));
        $this->assertCount(0, $crawler->filter('[data-testid="trade-reveal"]'));
    }

    /**
     * @return array<string, bool|null>
     */
    private function newFlagsByCard(TradeOffer $offer): array
    {
        $this->entityManager->clear();
        $flags = [];
        foreach ($this->entityManager->getRepository(TradeOfferLine::class)->findBy(['tradeOffer' => $offer->getId()]) as $line) {
            $flags[$line->getCard()->getName()] = $line->isNewForTaker();
        }
        ksort($flags);

        return $flags;
    }

    private function user(string $discordId): DiscordUser
    {
        $user = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);
        \assert($user instanceof DiscordUser);

        return $user;
    }

    private function card(string $name, CardRarityEnum $rarity): Card
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
        $this->entityManager->persist(
            new UserCard()
                ->setDiscordUser($user)
                ->setCard($card)
                ->setQuantity($quantity)
                ->setHoloQuantity(0),
        );
        $this->entityManager->flush();
    }

    /**
     * @param list<Card> $offered
     * @param list<Card> $requested
     */
    private function offer(DiscordUser $proposer, DiscordUser $receiver, array $offered, array $requested): TradeOffer
    {
        return static::getContainer()->get(TradeOfferService::class)->create(
            $proposer,
            $receiver,
            array_map(static fn (Card $card): TradeLineRequest => new TradeLineRequest($card, 1), $offered),
            array_map(static fn (Card $card): TradeLineRequest => new TradeLineRequest($card, 1), $requested),
        );
    }
}
