<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

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
use App\Exception\Trade\InvalidTradeOfferException;
use App\Exception\Trade\TradeOfferInvalidatedException;
use App\Exception\Trade\TradeOfferUnacceptableException;
use App\Service\Trade\TradeOfferService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TradeOfferServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private TradeOfferService $tradeOfferService;

    private Extension $extension;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tradeOfferService = self::getContainer()->get(TradeOfferService::class);

        $this->extension = new Extension()
            ->setName('Trade extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);
        $this->entityManager->flush();
    }

    // ------------------------------------------------------------- creation

    public function testCreateStoresBothSidesAsPending(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $offered = $this->createCard('Offered');
        $requested = $this->createCard('Requested');
        $this->giveCards($alice, $offered, quantity: 2);
        $this->giveCards($bob, $requested, quantity: 1);

        $offer = $this->tradeOfferService->create(
            $alice,
            $bob,
            [new TradeLineRequest($offered, 2)],
            [new TradeLineRequest($requested, 1)],
        );

        $this->assertSame(TradeOfferStatusEnum::PENDING, $offer->getStatus());
        $this->assertNull($offer->getResolvedAt());
        $this->assertCount(1, $offer->getOfferedLines());
        $this->assertCount(1, $offer->getRequestedLines());
        $this->assertSame(2, $offer->getOfferedLines()[0]->getNormalQuantity());
        $this->assertSame($requested->getId(), $offer->getRequestedLines()[0]->getCard()->getId());
    }

    public function testCreateRefusesSelfTrade(): void
    {
        $alice = $this->createUser('alice');
        $card = $this->createCard('Solo');
        $this->giveCards($alice, $card, quantity: 2);

        $this->expectException(InvalidTradeOfferException::class);
        $this->tradeOfferService->create($alice, $alice, [new TradeLineRequest($card, 1)], [new TradeLineRequest($card, 1)]);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function emptySideProvider(): iterable
    {
        yield 'nothing offered' => [true];
        yield 'nothing requested' => [false];
    }

    #[DataProvider('emptySideProvider')]
    public function testCreateRefusesAnEmptySide(bool $emptyOffered): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceCard = $this->createCard('Alice card');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $aliceCard, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);

        // a zero-quantity line is dropped by normalization, so the side is empty
        $offered = $emptyOffered ? [new TradeLineRequest($aliceCard, 0)] : [new TradeLineRequest($aliceCard, 1)];
        $requested = $emptyOffered ? [new TradeLineRequest($bobCard, 1)] : [];

        $this->expectException(InvalidTradeOfferException::class);
        $this->tradeOfferService->create($alice, $bob, $offered, $requested);
    }

    public function testCreateRefusesMoreCopiesThanOwned(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $card = $this->createCard('Scarce');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $card, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);

        $this->expectException(InvalidTradeOfferException::class);
        $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($card, 2)], [new TradeLineRequest($bobCard, 1)]);
    }

    public function testASingleCopyCannotBeEngagedInTwoOffers(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $card = $this->createCard('One copy');
        $bobCard = $this->createCard('Bob card');
        $carolCard = $this->createCard('Carol card');
        $this->giveCards($alice, $card, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);
        $this->giveCards($carol, $carolCard, quantity: 1);

        $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($card, 1)], [new TradeLineRequest($bobCard, 1)]);

        $this->expectException(InvalidTradeOfferException::class);
        $this->expectExceptionMessage('already engaged');
        $this->tradeOfferService->create($alice, $carol, [new TradeLineRequest($card, 1)], [new TradeLineRequest($carolCard, 1)]);
    }

    public function testReservationsAreCountedPerFinish(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $card = $this->createCard('Mixed finishes');
        $bobCard = $this->createCard('Bob card');
        $carolCard = $this->createCard('Carol card');
        // 1 normal + 1 holo (quantity is the TOTAL, holoQuantity a sub-count)
        $this->giveCards($alice, $card, quantity: 2, holoQuantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);
        $this->giveCards($carol, $carolCard, quantity: 1);

        $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($card, 1, 0)], [new TradeLineRequest($bobCard, 1)]);

        // the holo copy is untouched by the first offer: engaging it is fine
        $second = $this->tradeOfferService->create($alice, $carol, [new TradeLineRequest($card, 0, 1)], [new TradeLineRequest($carolCard, 1)]);
        $this->assertSame(TradeOfferStatusEnum::PENDING, $second->getStatus());
    }

    public function testCreateRefusesCardsTheReceiverDoesNotOwn(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceCard = $this->createCard('Alice card');
        $missing = $this->createCard('Bob never had it');
        $this->giveCards($alice, $aliceCard, quantity: 1);

        $this->expectException(InvalidTradeOfferException::class);
        $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($aliceCard, 1)], [new TradeLineRequest($missing, 1)]);
    }

    public function testDuplicateLinesOfTheSameCardAreMerged(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $card = $this->createCard('Duplicated line');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $card, quantity: 3);
        $this->giveCards($bob, $bobCard, quantity: 1);

        $offer = $this->tradeOfferService->create(
            $alice,
            $bob,
            [new TradeLineRequest($card, 1), new TradeLineRequest($card, 2)],
            [new TradeLineRequest($bobCard, 1)],
        );

        $this->assertCount(1, $offer->getOfferedLines());
        $this->assertSame(3, $offer->getOfferedLines()[0]->getNormalQuantity());
    }

    // ----------------------------------------------------------- acceptance

    public function testAcceptSwapsBothInventories(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceCard = $this->createCard('Alice card');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $aliceCard, quantity: 3, holoQuantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 2);

        // Alice gives 1 normal + 1 holo, Bob gives 1 normal
        $offer = $this->tradeOfferService->create(
            $alice,
            $bob,
            [new TradeLineRequest($aliceCard, 1, 1)],
            [new TradeLineRequest($bobCard, 1)],
        );

        $this->tradeOfferService->accept($offer, $bob);
        $this->entityManager->clear();

        $this->assertInventory($alice, $aliceCard, quantity: 1, holoQuantity: 0);
        $this->assertInventory($alice, $bobCard, quantity: 1, holoQuantity: 0);
        $this->assertInventory($bob, $aliceCard, quantity: 2, holoQuantity: 1);
        $this->assertInventory($bob, $bobCard, quantity: 1, holoQuantity: 0);

        $reloaded = $this->entityManager->find(TradeOffer::class, $offer->getId());
        $this->assertSame(TradeOfferStatusEnum::ACCEPTED, $reloaded?->getStatus());
        $this->assertNotNull($reloaded?->getResolvedAt());
    }

    public function testOnlyTheReceiverMayAccept(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $offer = $this->createSimpleOffer($alice, $bob);

        $this->expectException(InvalidTradeOfferException::class);
        $this->tradeOfferService->accept($offer, $alice);
    }

    public function testAcceptingTwiceIsRefused(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $offer = $this->createSimpleOffer($alice, $bob);

        $this->tradeOfferService->accept($offer, $bob);

        $this->expectException(InvalidTradeOfferException::class);
        $this->tradeOfferService->accept($offer, $bob);
    }

    public function testAcceptInvalidatesTheOfferWhenTheProposerLostTheCopies(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceCard = $this->createCard('Vanishing');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $aliceCard, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);

        $offer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($aliceCard, 1)], [new TradeLineRequest($bobCard, 1)]);

        // Alice loses the copy out of band (recycling, another trade…)
        $this->setInventory($alice, $aliceCard, quantity: 0, holoQuantity: 0);

        try {
            $this->tradeOfferService->accept($offer, $bob);
            $this->fail('The acceptance should have been refused.');
        } catch (TradeOfferInvalidatedException) {
            // expected
        }

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(TradeOffer::class, $offer->getId());
        // the INVALIDATED status is COMMITTED even though acceptance failed
        $this->assertSame(TradeOfferStatusEnum::INVALIDATED, $reloaded?->getStatus());
        // and nothing moved
        $this->assertInventory($bob, $bobCard, quantity: 1, holoQuantity: 0);
    }

    public function testAcceptStaysPendingWhenTheRECEIVERCannotCover(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceCard = $this->createCard('Alice card');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $aliceCard, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);

        $offer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($aliceCard, 1)], [new TradeLineRequest($bobCard, 1)]);

        $this->setInventory($bob, $bobCard, quantity: 0, holoQuantity: 0);

        try {
            $this->tradeOfferService->accept($offer, $bob);
            $this->fail('The acceptance should have been refused.');
        } catch (TradeOfferUnacceptableException) {
            // expected
        }

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(TradeOffer::class, $offer->getId());
        // the receiver's shortage may resolve itself: the offer survives
        $this->assertSame(TradeOfferStatusEnum::PENDING, $reloaded?->getStatus());
        $this->assertInventory($alice, $aliceCard, quantity: 1, holoQuantity: 0);
    }

    public function testTheReceiverOwnOutgoingOffersReserveTheirCopies(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $aliceCard = $this->createCard('Alice card');
        $bobCard = $this->createCard('Bob single copy');
        $carolCard = $this->createCard('Carol card');
        $this->giveCards($alice, $aliceCard, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);
        $this->giveCards($carol, $carolCard, quantity: 1);

        // Alice asks Bob for his only copy…
        $offer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($aliceCard, 1)], [new TradeLineRequest($bobCard, 1)]);
        // …but Bob already promised it to Carol
        $this->tradeOfferService->create($bob, $carol, [new TradeLineRequest($bobCard, 1)], [new TradeLineRequest($carolCard, 1)]);

        $this->expectException(TradeOfferUnacceptableException::class);
        $this->tradeOfferService->accept($offer, $bob);
    }

    public function testAcceptTransfersTheClaimOfAUniqueCard(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $unique = $this->createCard('One of one', unique: true);
        $unique->setClaimedBy($alice);
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $unique, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);
        $this->entityManager->flush();

        $offer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($unique, 1)], [new TradeLineRequest($bobCard, 1)]);
        $this->tradeOfferService->accept($offer, $bob);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Card::class, $unique->getId());
        $this->assertSame($bob->getDiscordId(), $reloaded?->getClaimedBy()?->getDiscordId());
        $this->assertInventory($bob, $unique, quantity: 1, holoQuantity: 0);
        $this->assertInventory($alice, $unique, quantity: 0, holoQuantity: 0);
    }

    public function testAUniqueNoLongerClaimedByItsGiverIsRefused(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $unique = $this->createCard('One of one', unique: true);
        $unique->setClaimedBy($alice);
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $unique, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);
        $this->entityManager->flush();

        $offer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($unique, 1)], [new TradeLineRequest($bobCard, 1)]);

        // the claim moved elsewhere while the offer was pending
        $unique->setClaimedBy($carol);
        $this->entityManager->flush();

        $this->expectException(TradeOfferInvalidatedException::class);
        $this->tradeOfferService->accept($offer, $bob);
    }

    public function testAcceptCreatesTheInventoryRowOfANeverOwnedCard(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceCard = $this->createCard('Brand new for Bob');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $aliceCard, quantity: 1, holoQuantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);

        $offer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($aliceCard, 0, 1)], [new TradeLineRequest($bobCard, 1)]);
        $this->tradeOfferService->accept($offer, $bob);

        $this->entityManager->clear();
        $this->assertInventory($bob, $aliceCard, quantity: 1, holoQuantity: 1);
    }

    public function testAnOfferIsAcceptableDespiteItsOwnReservation(): void
    {
        // regression guard: the offer being accepted must be EXCLUDED from the
        // reservation ledger, otherwise it competes with itself and never passes
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceCard = $this->createCard('Only copy');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $aliceCard, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);

        $offer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($aliceCard, 1)], [new TradeLineRequest($bobCard, 1)]);
        $this->tradeOfferService->accept($offer, $bob);

        $this->entityManager->clear();
        $this->assertSame(
            TradeOfferStatusEnum::ACCEPTED,
            $this->entityManager->find(TradeOffer::class, $offer->getId())?->getStatus(),
        );
    }

    // ------------------------------------------------- refusal / withdrawal

    public function testRefuseFreesTheReservedCopies(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $card = $this->createCard('One copy');
        $bobCard = $this->createCard('Bob card');
        $carolCard = $this->createCard('Carol card');
        $this->giveCards($alice, $card, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 1);
        $this->giveCards($carol, $carolCard, quantity: 1);

        $first = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($card, 1)], [new TradeLineRequest($bobCard, 1)]);
        $this->tradeOfferService->refuse($first, $bob);

        $second = $this->tradeOfferService->create($alice, $carol, [new TradeLineRequest($card, 1)], [new TradeLineRequest($carolCard, 1)]);
        $this->assertSame(TradeOfferStatusEnum::PENDING, $second->getStatus());
        $this->assertSame(TradeOfferStatusEnum::REFUSED, $first->getStatus());
    }

    public function testOnlyTheProposerMayCancelAndOnlyTheReceiverMayRefuse(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $offer = $this->createSimpleOffer($alice, $bob);

        try {
            $this->tradeOfferService->cancel($offer, $bob);
            $this->fail('The receiver must not be able to cancel.');
        } catch (InvalidTradeOfferException) {
            // expected
        }

        try {
            $this->tradeOfferService->refuse($offer, $alice);
            $this->fail('The proposer must not be able to refuse.');
        } catch (InvalidTradeOfferException) {
            // expected
        }

        $this->tradeOfferService->cancel($offer, $alice);
        $this->assertSame(TradeOfferStatusEnum::CANCELLED, $offer->getStatus());
    }

    // ------------------------------------------------------- lazy cleanup

    public function testInvalidateObviouslyInfeasibleOnlyFlagsDeadOffers(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $dead = $this->createCard('Lost card');
        $alive = $this->createCard('Kept card');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $dead, quantity: 1);
        $this->giveCards($alice, $alive, quantity: 1);
        $this->giveCards($bob, $bobCard, quantity: 2);

        $deadOffer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($dead, 1)], [new TradeLineRequest($bobCard, 1)]);
        $aliveOffer = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($alive, 1)], [new TradeLineRequest($bobCard, 1)]);

        $this->setInventory($alice, $dead, quantity: 0, holoQuantity: 0);

        $this->assertTrue($this->tradeOfferService->invalidateObviouslyInfeasible([$deadOffer, $aliveOffer]));
        $this->assertSame(TradeOfferStatusEnum::INVALIDATED, $deadOffer->getStatus());
        $this->assertSame(TradeOfferStatusEnum::PENDING, $aliveOffer->getStatus());
    }

    public function testSiblingReservationsDoNotMakeAnOfferLookDead(): void
    {
        // two offers share the same two copies; neither is dead on its own
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $card = $this->createCard('Two copies');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $card, quantity: 2);
        $this->giveCards($bob, $bobCard, quantity: 2);

        $first = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($card, 1)], [new TradeLineRequest($bobCard, 1)]);
        $second = $this->tradeOfferService->create($alice, $bob, [new TradeLineRequest($card, 1)], [new TradeLineRequest($bobCard, 1)]);

        $this->assertFalse($this->tradeOfferService->invalidateObviouslyInfeasible([$first, $second]));
        $this->assertSame(TradeOfferStatusEnum::PENDING, $first->getStatus());
        $this->assertSame(TradeOfferStatusEnum::PENDING, $second->getStatus());
    }

    // ----------------------------------------------------------- utilities

    private function createUser(string $prefix): DiscordUser
    {
        $user = new DiscordUser()
            ->setDiscordId($prefix . '-' . uniqid())
            ->setUsername(ucfirst($prefix))
        ;
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createCard(string $name, bool $unique = false): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setUnique($unique)
        ;
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function giveCards(DiscordUser $user, Card $card, int $quantity, int $holoQuantity = 0): void
    {
        $userCard = new UserCard()
            ->setDiscordUser($user)
            ->setCard($card)
            ->setQuantity($quantity)
            ->setHoloQuantity($holoQuantity)
        ;
        $this->entityManager->persist($userCard);
        $this->entityManager->flush();
    }

    private function setInventory(DiscordUser $user, Card $card, int $quantity, int $holoQuantity): void
    {
        $userCard = $this->entityManager->getRepository(UserCard::class)
            ->findOneBy(['discordUser' => $user, 'card' => $card])
        ;
        $this->assertNotNull($userCard);
        $userCard->setQuantity($quantity)->setHoloQuantity($holoQuantity);
        $this->entityManager->flush();
    }

    private function assertInventory(DiscordUser $user, Card $card, int $quantity, int $holoQuantity): void
    {
        $userCard = $this->entityManager->getRepository(UserCard::class)
            ->findOneBy(['discordUser' => $user, 'card' => $card])
        ;

        if (0 === $quantity && !$userCard instanceof UserCard) {
            return;
        }

        $this->assertInstanceOf(UserCard::class, $userCard);
        $this->assertSame($quantity, $userCard->getQuantity(), 'quantity of ' . $card->getName());
        $this->assertSame($holoQuantity, $userCard->getHoloQuantity(), 'holoQuantity of ' . $card->getName());
    }

    private function createSimpleOffer(DiscordUser $proposer, DiscordUser $receiver): TradeOffer
    {
        $offered = $this->createCard('Offered');
        $requested = $this->createCard('Requested');
        $this->giveCards($proposer, $offered, quantity: 1);
        $this->giveCards($receiver, $requested, quantity: 1);

        return $this->tradeOfferService->create(
            $proposer,
            $receiver,
            [new TradeLineRequest($offered, 1)],
            [new TradeLineRequest($requested, 1)],
        );
    }
}
