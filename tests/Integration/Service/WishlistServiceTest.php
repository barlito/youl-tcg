<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\MarketListing;
use App\Entity\WishlistEntry;
use App\Entity\WishlistUniverse;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\FeatureEnum;
use App\Exception\Wishlist\WishlistRefusedException;
use App\Service\Wishlist\WishlistService;
use App\Tests\FeatureFlagTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WishlistServiceTest extends KernelTestCase
{
    use FeatureFlagTrait;
    use MarketTestTrait;

    private WishlistService $service;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(WishlistService::class);
        $this->bootMarketFixtures();
    }

    public function testAnOwnedCardCanBeWishedAndUnwished(): void
    {
        $player = $this->createUser('wisher');
        $card = $this->createCard('Owned');
        $this->giveCards($player, $card, 1);

        $this->assertTrue($this->service->toggle($player, $card));
        $this->assertSame([(string) $card->getId() => true], $this->service->wishedCardIds($player));

        $this->assertFalse($this->service->toggle($player, $card));
        $this->assertSame([], $this->service->wishedCardIds($player));
    }

    public function testAddingTwiceKeepsASingleRow(): void
    {
        $player = $this->createUser('wisher');
        $card = $this->createCard('Owned');
        $this->giveCards($player, $card, 1);

        $first = $this->service->add($player, $card);
        $second = $this->service->add($player, $card);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertCount(1, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $player]));
    }

    public function testTheCapRefusesTheNextWishButIdempotentReAddsStillPass(): void
    {
        $player = $this->createUser('wisher');
        $cards = [];
        for ($i = 0; $i < WishlistService::MAX_ENTRIES; ++$i) {
            $cards[$i] = $this->createCard('Capped ' . $i);
            $this->giveCards($player, $cards[$i], 1);
            $this->service->add($player, $cards[$i]);
        }
        $extra = $this->createCard('Over the cap');
        $this->giveCards($player, $extra, 1);

        try {
            $this->service->add($player, $extra);
            $this->fail('The 31st wish must be refused.');
        } catch (WishlistRefusedException $exception) {
            $this->assertStringContainsString('wishlist est pleine', $exception->getUserMessage());
        }

        $this->service->add($player, $cards[0]);
        $this->assertCount(WishlistService::MAX_ENTRIES, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $player]));

        $this->service->remove($player, $cards[0]);
        $this->assertInstanceOf(WishlistEntry::class, $this->service->add($player, $extra));
    }

    public function testACardNeverSeenIsRefused(): void
    {
        $player = $this->createUser('wisher');
        $hidden = $this->createCard('Never seen');

        $this->assertFalse($this->service->canSee($player, $hidden));
        $this->expectException(WishlistRefusedException::class);

        $this->service->add($player, $hidden);
    }

    public function testACardOnAnActiveListingIsVisibleAndWishable(): void
    {
        $player = $this->createUser('wisher');
        $seller = $this->createUser('seller');
        $card = $this->createCard('On sale');
        $this->entityManager->persist(new MarketListing($seller, $card, false, 20));
        $this->entityManager->flush();

        $this->assertTrue($this->service->canSee($player, $card));
        $this->assertTrue($this->service->toggle($player, $card));
    }

    public function testAClosedListingNoLongerMakesTheCardVisible(): void
    {
        $player = $this->createUser('wisher');
        $seller = $this->createUser('seller');
        $card = $this->createCard('Sold already');
        $listing = new MarketListing($seller, $card, false, 20);
        $listing->close(\App\Enum\Market\MarketListingStatusEnum::WITHDRAWN, new \DateTimeImmutable());
        $this->entityManager->persist($listing);
        $this->entityManager->flush();

        $this->assertFalse($this->service->canSee($player, $card));
    }

    public function testADraftCardIsNeverVisible(): void
    {
        $player = $this->createUser('wisher');
        $card = $this->createCard('Draft');
        $this->giveCards($player, $card, 1);
        $card->setStatus(CardStatusEnum::DRAFT);
        $this->entityManager->flush();

        $this->assertFalse($this->service->canSee($player, $card));
    }

    public function testTheWishlistListFlagsWhatIsStillVisible(): void
    {
        $player = $this->createUser('wisher');
        $seller = $this->createUser('seller');
        $card = $this->createCard('Seen on the market');
        $listing = new MarketListing($seller, $card, false, 20);
        $this->entityManager->persist($listing);
        $this->entityManager->flush();
        $this->service->add($player, $card);

        $rows = $this->service->listEntries($player);
        $this->assertTrue($rows[0]['visible']);
        $this->assertTrue($rows[0]['listed']);

        $listing->close(\App\Enum\Market\MarketListingStatusEnum::WITHDRAWN, new \DateTimeImmutable());
        $this->entityManager->flush();

        $rows = $this->service->listEntries($player);
        $this->assertFalse($rows[0]['visible'], 'A card seen once but neither owned nor on sale is shown as a card back.');
        $this->assertFalse($rows[0]['listed']);
    }

    public function testUniverseWatchIsAToggleOnePerUniverse(): void
    {
        $player = $this->createUser('watcher');

        $this->assertTrue($this->service->toggleUniverse($player, $this->extension));
        $this->service->watchUniverse($player, $this->extension);
        $this->assertCount(1, $this->entityManager->getRepository(WishlistUniverse::class)->findBy(['player' => $player]));
        $this->assertTrue($this->service->isWatching($player, $this->extension));

        $this->assertFalse($this->service->toggleUniverse($player, $this->extension));
        $this->assertFalse($this->service->isWatching($player, $this->extension));
    }

    public function testTheWatchedUniverseCountsOnlyTheMissingCards(): void
    {
        $player = $this->createUser('watcher');
        $owned = $this->createCard('Mine');
        $this->createCard('Missing');
        $this->giveCards($player, $owned, 1);
        $this->service->watchUniverse($player, $this->extension);

        $rows = $this->service->listUniverses($player);

        $this->assertCount(1, $rows);
        // Filler (bootstrap) + Missing, minus the owned one
        $this->assertSame(2, $rows[0]['missing']);
    }

    public function testADraftUniverseCannotBeWatched(): void
    {
        $player = $this->createUser('watcher');
        $this->extension->setStatus(\App\Enum\Entity\ExtensionStatusEnum::DRAFT);
        $this->entityManager->flush();

        $this->expectException(WishlistRefusedException::class);

        $this->service->watchUniverse($player, $this->extension);
    }

    public function testNothingWorksWhileTheFeatureIsOff(): void
    {
        $player = $this->createUser('wisher');
        $card = $this->createCard('Owned');
        $this->giveCards($player, $card, 1);
        $this->service->add($player, $card);
        $this->setFeature(FeatureEnum::WISHLIST, false);

        $this->assertSame([], $this->service->wishedCardIds($player));
        $this->assertSame([], $this->service->wantedAmong($player, [(string) $card->getId()]));
        $actions = [
            fn () => $this->service->add($player, $card),
            fn () => $this->service->toggle($player, $card),
            fn () => $this->service->watchUniverse($player, $this->extension),
            fn () => $this->service->removeEntry($player, 'x'),
        ];

        foreach ($actions as $action) {
            try {
                $action();
                $this->fail('A mutation must be refused while the wishlist is off.');
            } catch (WishlistRefusedException $exception) {
                $this->assertStringContainsString('fermée', $exception->getUserMessage());
            }
        }
    }

    public function testWantedAmongCoversDirectWishesAndMissingCardsOfAWatchedUniverse(): void
    {
        $wanter = $this->createUser('wanter');
        $direct = $this->createCard('Direct');
        $watched = $this->createCard('Watched and missing');
        $watchedOwned = $this->createCard('Watched but owned');
        $this->giveCards($wanter, $direct, 1);
        $this->giveCards($wanter, $watchedOwned, 1);
        $this->service->add($wanter, $direct);
        $this->service->watchUniverse($wanter, $this->extension);

        $wanted = $this->service->wantedAmong($wanter, array_map(static fn ($card): string => (string) $card->getId(), [$direct, $watched, $watchedOwned]));

        $this->assertSame([(string) $direct->getId(), (string) $watched->getId()], array_keys($wanted));
    }

    public function testRemoveEntryOnlyTouchesTheOwnersRows(): void
    {
        $owner = $this->createUser('owner');
        $other = $this->createUser('other');
        $card = $this->createCard('Owned');
        $this->giveCards($owner, $card, 1);
        $entry = $this->service->add($owner, $card);

        $this->service->removeEntry($other, (string) $entry->getId());
        $this->service->removeEntry($other, 'not-a-uuid');
        $this->assertCount(1, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $owner]));

        $this->service->removeEntry($owner, (string) $entry->getId());
        $this->assertCount(0, $this->entityManager->getRepository(WishlistEntry::class)->findBy(['player' => $owner]));
    }
}
