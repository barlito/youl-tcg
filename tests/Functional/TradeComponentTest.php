<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\TradeLineRequest;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\TradeOffer;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Service\Trade\TradeOfferService;
use App\Twig\Components\TradeComposer;
use App\Twig\Components\TradeInbox;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class TradeComponentTest extends WebTestCase
{
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
            ->setName('Trade UI extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);
        $this->entityManager->flush();
    }

    public function testComposerBuildsAndSubmitsAnOffer(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $mine = $this->giveCard($barlito, 'Ma carte', quantity: 2);
        $theirs = $this->giveCard($juju, 'Sa carte', quantity: 1);

        $component = $this->composer();
        $html = (string) $component->render();

        $component->call('adjust', ['side' => 'offered', 'token' => $this->tokenOf($html, 'offered', $mine->getName()), 'finish' => 'normal', 'delta' => 1]);
        // not owned by the visitor: requested blind, through its masked tile
        $component->call('adjust', ['side' => 'requested', 'token' => $this->tokenOf($html, 'requested', null), 'finish' => 'normal', 'delta' => 1]);

        $this->assertSame(1, $component->component()->getOfferedCount());
        $this->assertSame(1, $component->component()->getRequestedCount());
        $this->assertTrue($component->component()->isComplete());

        $component->call('submit');

        $offers = $this->entityManager->getRepository(TradeOffer::class)->findBy(['proposer' => $barlito, 'receiver' => $juju]);
        $this->assertCount(1, $offers);
        $this->assertSame(TradeOfferStatusEnum::PENDING, $offers[0]->getStatus());
    }

    public function testStepperNeverExceedsTheAvailableCopies(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $mine = $this->giveCard($barlito, 'Une seule', quantity: 1);

        $component = $this->composer();
        $token = $this->tokenOf((string) $component->render(), 'offered', $mine->getName());

        // three clicks on a single owned copy: the server clamps at 1
        foreach (range(1, 3) as $ignored) {
            $component->call('adjust', ['side' => 'offered', 'token' => $token, 'finish' => 'normal', 'delta' => 1]);
        }

        $this->assertSame(1, $component->component()->getOfferedCount());
        $this->assertNotNull(
            $this->tile((string) $component->render(), 'offered', $mine->getName())->filter('[data-testid="plus-offered-normal"]')->attr('disabled'),
            'Le + se grise une fois le plafond atteint.',
        );

        // and never below zero
        foreach (range(1, 3) as $ignored) {
            $component->call('adjust', ['side' => 'offered', 'token' => $token, 'finish' => 'normal', 'delta' => -1]);
        }

        $this->assertSame(0, $component->component()->getOfferedCount());
    }

    public function testComposerIgnoresACardTheUserDoesNotOwn(): void
    {
        $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $notMine = $this->giveCard($juju, 'Pas à moi', quantity: 1);

        $component = $this->composer();
        $theirToken = $this->tokenOf((string) $component->render(), 'requested', null);

        // their token on my side, or a raw uuid: both unknown to my side
        $component->call('adjust', ['side' => 'offered', 'token' => $theirToken, 'finish' => 'normal', 'delta' => 1]);
        $component->call('adjust', ['side' => 'offered', 'token' => (string) $notMine->getId(), 'finish' => 'normal', 'delta' => 1]);
        $component->call('adjust', ['side' => 'requested', 'token' => (string) $notMine->getId(), 'finish' => 'normal', 'delta' => 1]);

        $this->assertSame(0, $component->component()->getOfferedCount());
        $this->assertSame(0, $component->component()->getRequestedCount());
    }

    public function testOnlyTheAvailableFinishesAreShownWithTheirCaps(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $mixed = $this->giveCard($barlito, 'Mixte', quantity: 3, holoQuantity: 1);
        $plain = $this->giveCard($barlito, 'Sans holo', quantity: 2);

        $html = (string) $this->composer()->render();

        $mixedTile = $this->tile($html, 'offered', $mixed->getName());
        $this->assertSame('2 dispo', trim($mixedTile->filter('[data-testid="cap-offered-normal"]')->text()));
        $this->assertSame('1 dispo', trim($mixedTile->filter('[data-testid="cap-offered-holo"]')->text()));

        $plainTile = $this->tile($html, 'offered', $plain->getName());
        $this->assertSame('2 dispo', trim($plainTile->filter('[data-testid="cap-offered-normal"]')->text()));
        $this->assertSame(0, $plainTile->filter('[data-testid="cap-offered-holo"]')->count(), 'Pas de stepper holo sans holo à donner.');
    }

    public function testCopiesEngagedElsewhereAreNotOfferedAgain(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $mine = $this->giveCard($barlito, 'Engagée', quantity: 3);
        $this->createOffer($barlito, $juju, $mine, $this->giveCard($juju, 'Demandée', quantity: 1));

        $html = (string) $this->composer()->render();

        $this->assertSame('2 dispo', trim($this->tile($html, 'offered', $mine->getName())->filter('[data-testid="cap-offered-normal"]')->text()));
    }

    public function testUnpublishedCardsAreNotListed(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $draft = $this->giveCard($barlito, 'Brouillon', quantity: 2);
        $draft->setStatus(CardStatusEnum::DRAFT);
        $this->entityManager->flush();

        $this->assertStringNotContainsString($draft->getName(), (string) $this->composer()->render());
    }

    public function testSearchFiltersButNeverHidesASelectedCard(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $picked = $this->giveCard($barlito, 'Zorglub le magnifique', quantity: 1);
        $other = $this->giveCard($barlito, 'Grominet des cavernes', quantity: 1);
        $ignored = $this->giveCard($barlito, 'Bidule sans rapport', quantity: 1);

        $component = $this->composer();
        $component->call('adjust', ['side' => 'offered', 'token' => $this->tokenOf((string) $component->render(), 'offered', $picked->getName()), 'finish' => 'normal', 'delta' => 1]);
        $rendered = (string) $component->set('search', 'grominet')->render();

        $this->assertStringContainsString($other->getName(), $rendered, 'La carte cherchée doit être visible.');
        $this->assertStringContainsString($picked->getName(), $rendered, 'Une carte déjà sélectionnée ne doit jamais disparaître.');
        $this->assertStringNotContainsString($ignored->getName(), $rendered, 'Une carte hors recherche doit être masquée.');
    }

    public function testTheUniverseFilterAppliesToBothSides(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $other = new Extension()->setName('Autre univers ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $mineHere = $this->giveCard($barlito, 'Ici à moi', quantity: 1);
        $mineThere = $this->giveCard($barlito, 'Là-bas à moi', quantity: 1, extension: $other);
        $theirsHere = $this->giveCard($juju, 'Ici à lui', quantity: 1);
        $this->giveCopy($barlito, $theirsHere);
        $theirsThere = $this->giveCard($juju, 'Là-bas à lui', quantity: 1, extension: $other);
        $this->giveCopy($barlito, $theirsThere);

        $component = $this->composer();
        $strip = new Crawler((string) $component->render())->filter('[data-testid="completion-strip"]');
        $this->assertGreaterThanOrEqual(2, $strip->filter('[data-testid="strip-tile"]')->count());
        $this->assertStringContainsString('univers=' . $other->getSlug(), $strip->html());

        $component->call('filterUniverse', ['slug' => $other->getSlug()]);
        $html = (string) $component->render();

        $this->assertStringContainsString($mineThere->getName(), $html);
        $this->assertStringContainsString($theirsThere->getName(), $html);
        $this->assertStringNotContainsString($mineHere->getName(), $html);
        $this->assertStringNotContainsString($theirsHere->getName(), $html);

        $component->call('filterUniverse', ['slug' => '']);
        $this->assertStringContainsString($mineHere->getName(), (string) $component->render());
    }

    public function testTheUniverseFilterIsReadFromTheUrl(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $here = $this->giveCard($barlito, 'Filtrée par url', quantity: 1);

        $this->client->request('GET', '/echanges/nouveau/' . self::JUJU . '?univers=' . $this->extension->getSlug());

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        $this->assertStringContainsString($here->getName(), $crawler->filter('[data-testid="offered-list"]')->html());
        $this->assertStringContainsString('border-primary', (string) $crawler->filter('[data-testid="strip-tile"][data-carousel-active]')->attr('class'));
    }

    public function testTheStickyRecapSummarisesBothSides(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $mine = $this->giveCard($barlito, 'Donnée', quantity: 3, holoQuantity: 1, rarity: CardRarityEnum::RARE);
        $this->giveCard($juju, 'Reçue inconnue', quantity: 1, rarity: CardRarityEnum::LEGENDARY);

        $component = $this->composer();
        $html = (string) $component->render();
        $this->assertNotNull(new Crawler($html)->filter('[data-testid="submit-offer"]')->attr('disabled'), 'Rien à envoyer tant qu\'un côté est vide.');

        $myToken = $this->tokenOf($html, 'offered', $mine->getName());
        $component->call('adjust', ['side' => 'offered', 'token' => $myToken, 'finish' => 'normal', 'delta' => 1]);
        $component->call('adjust', ['side' => 'offered', 'token' => $myToken, 'finish' => 'holo', 'delta' => 1]);
        $component->call('adjust', ['side' => 'requested', 'token' => $this->tokenOf($html, 'requested', null), 'finish' => 'normal', 'delta' => 1]);

        $bar = new Crawler((string) $component->render())->filter('[data-testid="composer-bar"]');
        $this->assertSame('2', trim($bar->filter('[data-testid="recap-offered-count"]')->text()));
        $this->assertSame('1', trim($bar->filter('[data-testid="recap-requested-count"]')->text()));
        $this->assertStringContainsString($mine->getName() . ' ×1 ✦×1', $bar->filter('[data-testid="recap-offered-items"]')->text());
        $this->assertStringContainsString('Inconnue (' . CardRarityEnum::LEGENDARY->label() . ')', $bar->filter('[data-testid="recap-requested-items"]')->text());
        $this->assertNull($bar->filter('[data-testid="submit-offer"]')->attr('disabled'));
        $this->assertStringContainsString('addAttribute(disabled)', (string) $bar->filter('[data-testid="submit-offer"]')->attr('data-loading'));
    }

    public function testMobileTabsSwitchTheShownSide(): void
    {
        $this->authenticateClient($this->client, self::BARLITO);

        $component = $this->composer();
        $crawler = new Crawler((string) $component->render());
        $this->assertStringNotContainsString('hidden', (string) $crawler->filter('[data-testid="offered-column"]')->attr('class'));
        $this->assertStringContainsString('hidden lg:block', (string) $crawler->filter('[data-testid="requested-column"]')->attr('class'));

        $component->call('showSide', ['side' => 'requested']);
        $crawler = new Crawler((string) $component->render());
        $this->assertStringContainsString('hidden lg:block', (string) $crawler->filter('[data-testid="offered-column"]')->attr('class'));
        $this->assertSame('true', $crawler->filter('[data-testid="tab-requested"]')->attr('aria-selected'));
    }

    public function testTheirColumnMasksTheCardsTheVisitorDoesNotOwn(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $shared = $this->giveCard($juju, 'Carte partagée', quantity: 1);
        $this->giveCopy($barlito, $shared);
        $unknown = $this->giveCard($juju, 'Carte secrète', quantity: 2, holoQuantity: 1);

        $component = $this->composer();
        $html = (string) $component->render();

        $this->assertStringNotContainsString(
            $unknown->getName(),
            $html,
            'Le nom d\'une carte que le visiteur ne possède pas ne doit apparaître nulle part dans le DOM.',
        );
        $this->assertStringContainsString($shared->getName(), $html, 'Une carte déjà possédée reste lisible.');

        $theirs = new Crawler($html)->filter('[data-testid="requested-list"]');
        $this->assertSame(1, $theirs->filter('[data-testid="masked-card"]')->count());
        $this->assertStringContainsString('Carte inconnue', $theirs->html());

        // masquée mais toujours demandable : on demande à l'aveugle
        $component->call('adjust', ['side' => 'requested', 'token' => $this->tokenOf($html, 'requested', null), 'finish' => 'holo', 'delta' => 1]);
        $this->assertSame(1, $component->component()->getRequestedCount());
    }

    public function testNoMaskedCardUuidEverReachesTheDom(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $mine = $this->giveCard($barlito, 'La mienne', quantity: 1);
        $unknown = $this->giveCard($juju, 'Inconnue au bataillon', quantity: 1);

        $component = $this->composer();
        $html = (string) $component->render();
        $this->assertStringNotContainsString((string) $unknown->getId(), $html);

        // selected, the masked card now lives in the checksummed props too
        $component->call('adjust', ['side' => 'requested', 'token' => $this->tokenOf($html, 'requested', null), 'finish' => 'normal', 'delta' => 1]);
        $component->call('adjust', ['side' => 'offered', 'token' => $this->tokenOf($html, 'offered', $mine->getName()), 'finish' => 'normal', 'delta' => 1]);

        $this->assertSame(1, $component->component()->getRequestedCount());
        $this->assertStringNotContainsString((string) $unknown->getId(), (string) $component->render());

        $this->client->request('GET', '/echanges/nouveau/' . self::JUJU);
        $this->assertStringNotContainsString((string) $unknown->getId(), (string) $this->client->getResponse()->getContent());
    }

    public function testTheSearchFilterIsNoOracleOnMaskedNames(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $shared = $this->giveCard($juju, 'Zorglub partagé', quantity: 1);
        $this->giveCopy($barlito, $shared);
        $this->giveCard($juju, 'Zorglub secret', quantity: 1);

        $component = $this->composer();
        $theirs = fn (string $needle): Crawler => new Crawler((string) $component->set('search', $needle)->render())
            ->filter('[data-testid="requested-list"]')
        ;

        // le filtre ne trie que les cartes visibles…
        $this->assertStringNotContainsString($shared->getName(), $theirs('zzzz')->html());
        $this->assertStringContainsString($shared->getName(), $theirs('zorglub')->html());

        // …et les masquées restent affichées quoi qu'on tape : sinon leur
        // apparition/disparition révélerait le nom qu'on cherche à cacher
        $this->assertSame(1, $theirs('zzzz')->filter('[data-testid="masked-card"]')->count());
        $this->assertSame(1, $theirs('secret')->filter('[data-testid="masked-card"]')->count());
    }

    public function testASentOfferKeepsTheRequestedUnknownCardMasked(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $mine = $this->giveCard($barlito, 'Ma carte', quantity: 1);
        $unknown = $this->giveCard($juju, 'Carte convoitée', quantity: 1);
        $this->createOffer($barlito, $juju, $mine, $unknown);

        $html = (string) $this->createLiveComponent(TradeInbox::class, client: $this->client)->render();

        $this->assertStringNotContainsString(
            $unknown->getName(),
            $html,
            'Demander une carte à l\'aveugle ne doit pas la révéler dans l\'offre envoyée.',
        );
        $this->assertStringContainsString($mine->getName(), $html);
        $this->assertSame(1, new Crawler($html)->filter('[data-testid="sent-offers"] [data-testid="masked-card"]')->count());
    }

    public function testAReceivedOfferMasksAnOfferedCardTheReaderDoesNotOwn(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offered = $this->giveCard($barlito, 'Trésor inconnu', quantity: 1, rarity: CardRarityEnum::LEGENDARY);
        $requested = $this->giveCard($juju, 'Ma commune en double', quantity: 2);
        $this->createOffer($barlito, $juju, $offered, $requested);

        $html = (string) $this->createLiveComponent(TradeInbox::class, client: $this->client)->render();

        $this->assertStringNotContainsString(
            $offered->getName(),
            $html,
            'Une carte proposée que le destinataire ne possède pas ne doit pas apparaître dans le HTML brut.',
        );
        // ce qu'il donne est à lui : toujours lisible
        $this->assertStringContainsString($requested->getName(), $html);

        $received = new Crawler($html)->filter('[data-testid="received-offers"]');
        $this->assertSame(1, $received->filter('[data-testid="masked-card"]')->count());
        $this->assertStringContainsString(
            CardRarityEnum::LEGENDARY->label(),
            $received->filter('[data-testid="masked-rarity"]')->text(),
            'La rareté reste affichée : c\'est à elle que se juge une offre à l\'aveugle.',
        );
        $this->assertSame(1, $received->filter('[data-testid="masking-hint"]')->count());
    }

    public function testAReceivedOfferShowsAnOfferedCardTheReaderAlreadyOwns(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offered = $this->giveCard($barlito, 'Déjà vue', quantity: 1);
        $this->giveCopy($juju, $offered);
        $requested = $this->giveCard($juju, 'Demandée', quantity: 1);
        $this->createOffer($barlito, $juju, $offered, $requested);

        $html = (string) $this->createLiveComponent(TradeInbox::class, client: $this->client)->render();
        $received = new Crawler($html)->filter('[data-testid="received-offers"]');

        $this->assertStringContainsString($offered->getName(), $html, 'Une carte déjà possédée reste en clair.');
        $this->assertSame(0, $received->filter('[data-testid="masked-card"]')->count());
        $this->assertSame(0, $received->filter('[data-testid="masking-hint"]')->count());
    }

    public function testTheHistoryNeverLeaksACardTheReaderDoesNotOwn(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offered = $this->giveCard($barlito, 'Jamais possédée', quantity: 1);
        $requested = $this->giveCard($juju, 'Demandée', quantity: 1);
        $offer = $this->createOffer($barlito, $juju, $offered, $requested);

        // refusée : rien n'a changé de main, la carte proposée reste inconnue
        static::getContainer()->get(TradeOfferService::class)->refuse($offer, $juju);

        $html = (string) $this->createLiveComponent(TradeInbox::class, client: $this->client)->render();

        $this->assertSame(1, new Crawler($html)->filter('[data-testid="trade-history"]')->count());
        $this->assertStringNotContainsString($offered->getName(), $html, 'L\'historique ne doit rien révéler non plus.');
    }

    public function testInboxAcceptSwapsTheCards(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offered = $this->giveCard($barlito, 'Offerte', quantity: 1);
        $requested = $this->giveCard($juju, 'Demandée', quantity: 1);
        $offer = $this->createOffer($barlito, $juju, $offered, $requested);

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $component->call('accept', ['offerId' => (string) $offer->getId()]);

        $this->assertNull($component->component()->error);
        $this->entityManager->clear();
        $this->assertSame(
            TradeOfferStatusEnum::ACCEPTED,
            $this->entityManager->find(TradeOffer::class, $offer->getId())?->getStatus(),
        );
        $this->assertSame(1, $this->ownedQuantity($juju, $offered));
        $this->assertSame(1, $this->ownedQuantity($barlito, $requested));
    }

    public function testAcceptingAsksForAConfirmationFirst(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offered = $this->giveCard($barlito, 'Offerte', quantity: 1);
        $requested = $this->giveCard($juju, 'Demandée', quantity: 1);
        $offer = $this->createOffer($barlito, $juju, $offered, $requested);

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $received = new Crawler((string) $component->render())->filter('[data-testid="received-offers"]');
        $this->assertSame(0, $received->filter('[data-testid="confirm-accept"]')->count());
        $this->assertSame('askAccept', $received->filter('[data-testid="accept-offer"]')->attr('data-live-action-param'));

        $component->call('askAccept', ['offerId' => (string) $offer->getId()]);
        $received = new Crawler((string) $component->render())->filter('[data-testid="received-offers"]');
        $this->assertSame(1, $received->filter('[data-testid="accept-confirmation"]')->count());
        $this->assertSame('accept', $received->filter('[data-testid="confirm-accept"]')->attr('data-live-action-param'));

        // asking changed nothing yet
        $this->assertSame(0, $this->ownedQuantity($juju, $offered));

        $component->call('abortAccept');
        $this->assertSame(0, new Crawler((string) $component->render())->filter('[data-testid="confirm-accept"]')->count());
    }

    public function testInboxRefusalFreesTheReservation(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offered = $this->giveCard($barlito, 'Offerte', quantity: 1);
        $requested = $this->giveCard($juju, 'Demandée', quantity: 1);
        $offer = $this->createOffer($barlito, $juju, $offered, $requested);

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $component->call('refuse', ['offerId' => (string) $offer->getId()]);

        $this->entityManager->clear();
        $this->assertSame(
            TradeOfferStatusEnum::REFUSED,
            $this->entityManager->find(TradeOffer::class, $offer->getId())?->getStatus(),
        );
        // nothing moved
        $this->assertSame(1, $this->ownedQuantity($barlito, $offered));
        $this->assertSame(0, $this->ownedQuantity($barlito, $requested));
    }

    public function testTheReceiverCannotCancelSomeoneElsesOffer(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offered = $this->giveCard($barlito, 'Offerte', quantity: 1);
        $requested = $this->giveCard($juju, 'Demandée', quantity: 1);
        $offer = $this->createOffer($barlito, $juju, $offered, $requested);

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $component->call('cancel', ['offerId' => (string) $offer->getId()]);

        $this->assertNotNull($component->component()->error);
        $this->entityManager->clear();
        $this->assertSame(
            TradeOfferStatusEnum::PENDING,
            $this->entityManager->find(TradeOffer::class, $offer->getId())?->getStatus(),
        );
    }

    public function testAnOfferOfOtherPlayersIsNotActionable(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->user(self::JUJU);
        $offered = $this->giveCard($barlito, 'Offerte', quantity: 1);
        $requested = $this->giveCard($juju, 'Demandée', quantity: 1);
        $offer = $this->createOffer($barlito, $juju, $offered, $requested);

        // a third player, party to nothing, tries to accept the offer
        $stranger = new DiscordUser()->setDiscordId('stranger-' . uniqid())->setUsername('Stranger');
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($stranger);
        $this->client->getCookieJar()->set(new Cookie('jwt', $token));

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $component->call('accept', ['offerId' => (string) $offer->getId()]);

        $this->assertNotNull($component->component()->error);
        $this->entityManager->clear();
        $this->assertSame(
            TradeOfferStatusEnum::PENDING,
            $this->entityManager->find(TradeOffer::class, $offer->getId())?->getStatus(),
        );
    }

    public function testAMalformedOfferIdIsAPlainError(): void
    {
        $this->authenticateClient($this->client, self::JUJU);

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $component->call('accept', ['offerId' => 'not-a-uuid']);

        $this->assertSame('Cette offre n\'existe plus.', $component->component()->error);
    }

    public function testTradePagesRenderAndUnknownCounterpartIs404(): void
    {
        $this->authenticateClient($this->client, self::BARLITO);

        $this->client->request('GET', '/echanges');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/echanges/nouveau');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/echanges/nouveau/' . self::JUJU);
        self::assertResponseIsSuccessful();

        // trading with yourself is not a thing
        $this->client->request('GET', '/echanges/nouveau/' . self::BARLITO);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/echanges/nouveau/nobody-here');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHeaderBadgeCountsPendingReceivedOffers(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $offered = $this->giveCard($barlito, 'Offerte', quantity: 1);
        $requested = $this->giveCard($juju, 'Demandée', quantity: 1);
        $this->createOffer($barlito, $juju, $offered, $requested);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertSame('1', trim($crawler->filter('[data-testid="pending-trades-badge"]')->text()));
    }

    // ----------------------------------------------------------- utilities

    private function user(string $discordId): DiscordUser
    {
        $user = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);
        \assert($user instanceof DiscordUser);

        return $user;
    }

    private function composer(): TestLiveComponent
    {
        return $this->createLiveComponent(TradeComposer::class, data: ['counterpartId' => self::JUJU], client: $this->client);
    }

    /**
     * The tile of a card (by name), or the first masked tile when $name is null.
     */
    private function tile(string $html, string $side, ?string $name): Crawler
    {
        $tiles = new Crawler($html)->filter(\sprintf('[data-testid="%s-column"] [data-testid="trade-tile"]', $side))
            ->reduce(static fn (Crawler $tile): bool => null === $name
                ? $tile->filter('[data-testid="masked-card"]')->count() > 0
                : str_contains($tile->text(), $name))
        ;
        $this->assertGreaterThan(0, $tiles->count(), \sprintf('No %s tile for "%s".', $side, $name ?? 'masked card'));

        return $tiles->first();
    }

    private function tokenOf(string $html, string $side, ?string $name): string
    {
        return (string) $this->tile($html, $side, $name)->filter('[data-live-token-param]')->attr('data-live-token-param');
    }

    private function giveCard(
        DiscordUser $user,
        string $name,
        int $quantity,
        int $holoQuantity = 0,
        CardRarityEnum $rarity = CardRarityEnum::COMMON,
        ?Extension $extension = null,
    ): Card {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension ?? $this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity($rarity)
        ;
        $this->entityManager->persist($card);
        $this->entityManager->persist(
            new UserCard()
                ->setDiscordUser($user)
                ->setCard($card)
                ->setQuantity($quantity)
                ->setHoloQuantity($holoQuantity),
        );
        $this->entityManager->flush();

        return $card;
    }

    private function giveCopy(DiscordUser $user, Card $card, int $quantity = 1): void
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

    private function createOffer(DiscordUser $proposer, DiscordUser $receiver, Card $offered, Card $requested): TradeOffer
    {
        return static::getContainer()->get(TradeOfferService::class)->create(
            $proposer,
            $receiver,
            [new TradeLineRequest($offered, 1)],
            [new TradeLineRequest($requested, 1)],
        );
    }

    private function ownedQuantity(DiscordUser $user, Card $card): int
    {
        return $this->entityManager->getRepository(UserCard::class)
            ->findOneBy(['discordUser' => $user, 'card' => $card])?->getQuantity() ?? 0
        ;
    }
}
