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
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

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

        $component = $this->createLiveComponent(
            TradeComposer::class,
            data: ['counterpartId' => self::JUJU],
            client: $this->client,
        );

        $component->call('adjust', ['side' => 'offered', 'cardId' => (string) $mine->getId(), 'finish' => 'normal', 'delta' => 1]);
        $component->call('adjust', ['side' => 'requested', 'cardId' => (string) $theirs->getId(), 'finish' => 'normal', 'delta' => 1]);

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

        $component = $this->createLiveComponent(
            TradeComposer::class,
            data: ['counterpartId' => self::JUJU],
            client: $this->client,
        );

        // three clicks on a single owned copy: the server clamps at 1
        foreach (range(1, 3) as $ignored) {
            $component->call('adjust', ['side' => 'offered', 'cardId' => (string) $mine->getId(), 'finish' => 'normal', 'delta' => 1]);
        }

        $this->assertSame(1, $component->component()->getOfferedCount());

        // and never below zero
        foreach (range(1, 3) as $ignored) {
            $component->call('adjust', ['side' => 'offered', 'cardId' => (string) $mine->getId(), 'finish' => 'normal', 'delta' => -1]);
        }

        $this->assertSame(0, $component->component()->getOfferedCount());
    }

    public function testComposerIgnoresACardTheUserDoesNotOwn(): void
    {
        $this->authenticateClient($this->client, self::BARLITO);
        $juju = $this->user(self::JUJU);
        $notMine = $this->giveCard($juju, 'Pas à moi', quantity: 1);

        $component = $this->createLiveComponent(
            TradeComposer::class,
            data: ['counterpartId' => self::JUJU],
            client: $this->client,
        );

        $component->call('adjust', ['side' => 'offered', 'cardId' => (string) $notMine->getId(), 'finish' => 'normal', 'delta' => 1]);

        $this->assertSame(0, $component->component()->getOfferedCount());
    }

    public function testSearchFiltersButNeverHidesASelectedCard(): void
    {
        $barlito = $this->authenticateClient($this->client, self::BARLITO);
        $picked = $this->giveCard($barlito, 'Zorglub le magnifique', quantity: 1);
        $other = $this->giveCard($barlito, 'Grominet des cavernes', quantity: 1);
        $ignored = $this->giveCard($barlito, 'Bidule sans rapport', quantity: 1);

        $component = $this->createLiveComponent(
            TradeComposer::class,
            data: ['counterpartId' => self::JUJU],
            client: $this->client,
        );
        $component->call('adjust', ['side' => 'offered', 'cardId' => (string) $picked->getId(), 'finish' => 'normal', 'delta' => 1]);
        $rendered = (string) $component->set('searchMine', 'grominet')->render();

        $this->assertStringContainsString($other->getName(), $rendered, 'La carte cherchée doit être visible.');
        $this->assertStringContainsString($picked->getName(), $rendered, 'Une carte déjà sélectionnée ne doit jamais disparaître.');
        $this->assertStringNotContainsString($ignored->getName(), $rendered, 'Une carte hors recherche doit être masquée.');
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

    private function giveCard(DiscordUser $user, string $name, int $quantity, int $holoQuantity = 0): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
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
