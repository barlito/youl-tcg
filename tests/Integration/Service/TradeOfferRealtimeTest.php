<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\TradeLineRequest;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\Notification;
use App\Entity\TradeOffer;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Exception\Trade\InvalidTradeOfferException;
use App\Exception\Trade\TradeOfferInvalidatedException;
use App\Exception\Trade\TradeOfferUnacceptableException;
use App\Exception\Trade\TradesClosedException;
use App\Service\Trade\TradeOfferService;
use App\Tests\FeatureFlagTrait;
use App\Tests\Support\SpyHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every trade transition is announced post-commit to the right players, and
 * nothing is announced for what did not happen.
 */
final class TradeOfferRealtimeTest extends KernelTestCase
{
    use FeatureFlagTrait;

    private EntityManagerInterface $entityManager;

    private TradeOfferService $tradeOfferService;

    private SpyHub $hub;

    private Extension $extension;

    private DiscordUser $alice;

    private DiscordUser $bob;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tradeOfferService = self::getContainer()->get(TradeOfferService::class);
        $this->hub = self::getContainer()->get(SpyHub::class);

        $this->extension = new Extension()
            ->setName('Realtime trades ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);
        $this->alice = $this->createUser('Alice');
        $this->bob = $this->createUser('Bob');
    }

    public function testCreationNotifiesTheReceiverAndRefreshesBothPlayers(): void
    {
        $offer = $this->createOffer();

        $this->assertSame(['trades-changed'], $this->eventTypesOf($this->alice));
        $this->assertSame(['trades-changed', 'notification'], $this->eventTypesOf($this->bob));
        $this->assertSame(
            ['offerId' => (string) $offer->getId(), 'status' => 'pending'],
            $this->eventsOf($this->bob)[0]['payload'],
        );
        $this->assertSame('Alice te propose un échange', $this->eventsOf($this->bob)[1]['payload']['message']);
        $this->assertSame('/echanges', $this->eventsOf($this->bob)[1]['payload']['link']);

        $this->assertNotifications($this->bob, [NotificationTypeEnum::TRADE_RECEIVED]);
        $this->assertNotifications($this->alice, []);
        $notification = $this->notificationsOf($this->bob)[0];
        $this->assertSame(['playerName' => 'Alice', 'playerId' => $this->alice->getDiscordId()], $notification->getPayload());
    }

    public function testAcceptanceNotifiesTheProposerAndRefreshesBothCollections(): void
    {
        $offer = $this->createOffer();
        $this->hub->reset();

        $this->tradeOfferService->accept($offer, $this->bob);

        $this->assertSame(['inventory-changed', 'trades-changed', 'notification'], $this->eventTypesOf($this->alice));
        $this->assertSame(['inventory-changed', 'trades-changed'], $this->eventTypesOf($this->bob));
        $this->assertSame('accepted', $this->eventsOf($this->bob)[1]['payload']['status']);
        $this->assertSame('Bob a accepté ton échange', $this->eventsOf($this->alice)[2]['payload']['message']);
        $this->assertNotifications($this->alice, [NotificationTypeEnum::TRADE_ACCEPTED]);
    }

    public function testRefusalNotifiesTheProposer(): void
    {
        $offer = $this->createOffer();
        $this->hub->reset();

        $this->tradeOfferService->refuse($offer, $this->bob);

        $this->assertSame(['trades-changed', 'notification'], $this->eventTypesOf($this->alice));
        $this->assertSame(['trades-changed'], $this->eventTypesOf($this->bob));
        $this->assertSame('Bob a refusé ton échange', $this->eventsOf($this->alice)[1]['payload']['message']);
        $this->assertNotifications($this->alice, [NotificationTypeEnum::TRADE_REFUSED]);
    }

    public function testCancellationSilentlyDropsTheOfferFromTheReceiverBox(): void
    {
        $offer = $this->createOffer();
        $this->hub->reset();

        $this->tradeOfferService->cancel($offer, $this->alice);

        $this->assertSame(['trades-changed'], $this->eventTypesOf($this->bob));
        $this->assertSame('cancelled', $this->eventsOf($this->bob)[0]['payload']['status']);
        $this->assertSame(['trades-changed'], $this->eventTypesOf($this->alice));
        // only the creation notified Bob: no entry for the cancellation
        $this->assertNotifications($this->bob, [NotificationTypeEnum::TRADE_RECEIVED]);
        $this->assertNotifications($this->alice, []);
    }

    public function testAnInvalidationAtAcceptanceRefreshesBothPlayers(): void
    {
        $offered = $this->createCard('Offered');
        $offer = $this->createOffer($offered);
        $this->setQuantity($this->alice, $offered, 0);
        $this->hub->reset();

        try {
            $this->tradeOfferService->accept($offer, $this->bob);
            $this->fail('Expected TradeOfferInvalidatedException');
        } catch (TradeOfferInvalidatedException) {
        }

        $this->assertSame(['trades-changed'], $this->eventTypesOf($this->alice));
        $this->assertSame(['trades-changed'], $this->eventTypesOf($this->bob));
        $this->assertSame('invalidated', $this->eventsOf($this->alice)[0]['payload']['status']);
    }

    public function testTheDisplayTimeSweepAnnouncesItsInvalidations(): void
    {
        $offered = $this->createCard('Offered');
        $offer = $this->createOffer($offered);
        $this->setQuantity($this->alice, $offered, 0);
        $this->hub->reset();

        $this->assertTrue($this->tradeOfferService->invalidateObviouslyInfeasible([$offer]));

        $this->assertSame(['trades-changed'], $this->eventTypesOf($this->alice));
        $this->assertSame(['trades-changed'], $this->eventTypesOf($this->bob));
    }

    public function testAnAcceptanceThatChangesNothingIsNotAnnounced(): void
    {
        $requested = $this->createCard('Requested');
        $offer = $this->createOffer(requested: $requested);
        // Bob no longer has the requested copy: the offer stays pending
        $this->setQuantity($this->bob, $requested, 0);
        $this->hub->reset();

        try {
            $this->tradeOfferService->accept($offer, $this->bob);
            $this->fail('Expected TradeOfferUnacceptableException');
        } catch (TradeOfferUnacceptableException) {
        }

        $this->assertSame([], $this->hub->getUpdates());
        $this->assertNotifications($this->alice, []);
    }

    public function testAFailedCreationIsNeverAnnounced(): void
    {
        $offered = $this->createCard('Offered');
        $requested = $this->createCard('Requested');
        $this->give($this->alice, $offered, 1);
        $this->give($this->bob, $requested, 1);

        try {
            // two copies asked while Alice holds one: refused inside the transaction
            $this->tradeOfferService->create($this->alice, $this->bob, [new TradeLineRequest($offered, 2)], [new TradeLineRequest($requested, 1)]);
            $this->fail('Expected InvalidTradeOfferException');
        } catch (InvalidTradeOfferException) {
        }

        $this->assertSame([], $this->hub->getUpdates());
        $this->assertNotifications($this->bob, []);
    }

    public function testNothingIsPublishedWhileTradesAreOff(): void
    {
        $offered = $this->createCard('Offered');
        $offer = $this->createOffer($offered);
        $this->setQuantity($this->alice, $offered, 0);
        $this->hub->reset();
        $this->setFeature(FeatureEnum::TRADES, false);

        try {
            $this->tradeOfferService->refuse($offer, $this->bob);
            $this->fail('Expected TradesClosedException');
        } catch (TradesClosedException) {
        }
        $this->assertFalse($this->tradeOfferService->invalidateObviouslyInfeasible([$offer]));

        $this->assertSame([], $this->hub->getUpdates());
        $this->assertNotifications($this->alice, []);
    }

    public function testAHubFailureNeverBreaksTheTrade(): void
    {
        $offer = $this->createOffer();
        $this->hub->failWith(new \RuntimeException('Hub down'));

        $this->tradeOfferService->accept($offer, $this->bob);

        $this->entityManager->clear();
        $this->assertTrue($this->entityManager->find(TradeOffer::class, $offer->getId())?->getStatus()->isFinal());
        // the entry is stored even though its live push failed
        $this->assertNotifications($this->alice, [NotificationTypeEnum::TRADE_ACCEPTED]);
    }

    // ----------------------------------------------------------- utilities

    private function createOffer(?Card $offered = null, ?Card $requested = null): TradeOffer
    {
        $offered ??= $this->createCard('Offered');
        $requested ??= $this->createCard('Requested');
        $this->give($this->alice, $offered, 1);
        $this->give($this->bob, $requested, 1);

        return $this->tradeOfferService->create($this->alice, $this->bob, [new TradeLineRequest($offered, 1)], [new TradeLineRequest($requested, 1)]);
    }

    /**
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    private function eventsOf(DiscordUser $user): array
    {
        $events = [];
        foreach ($this->hub->getUpdates() as $index => $update) {
            if ($update->getTopics() === ['https://localhost/users/' . $user->getDiscordId()]) {
                $this->assertTrue($update->isPrivate());
                $events[] = $this->hub->getEvents()[$index];
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function eventTypesOf(DiscordUser $user): array
    {
        return array_map(static fn (array $event): string => $event['type'], $this->eventsOf($user));
    }

    /**
     * @return list<Notification>
     */
    private function notificationsOf(DiscordUser $user): array
    {
        return array_values($this->entityManager->getRepository(Notification::class)->findBy(['recipient' => $user]));
    }

    /**
     * @param list<NotificationTypeEnum> $expected
     */
    private function assertNotifications(DiscordUser $user, array $expected): void
    {
        $this->assertSame($expected, array_map(static fn (Notification $notification): NotificationTypeEnum => $notification->getType(), $this->notificationsOf($user)));
    }

    private function createUser(string $name): DiscordUser
    {
        $user = new DiscordUser()
            ->setDiscordId((string) random_int(10 ** 15, 10 ** 16))
            ->setUsername($name)
        ;
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createCard(string $name): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
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

    private function setQuantity(DiscordUser $user, Card $card, int $quantity): void
    {
        $row = $this->entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $user, 'card' => $card]);
        $this->assertNotNull($row);
        $row->setQuantity($quantity)->setHoloQuantity(0);
        $this->entityManager->flush();
    }
}
