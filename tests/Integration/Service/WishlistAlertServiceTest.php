<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Entity\WishlistAlert;
use App\Enum\FeatureEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Service\Market\MarketListingService;
use App\Service\Wishlist\WishlistAlertService;
use App\Service\Wishlist\WishlistService;
use App\Tests\FeatureFlagTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WishlistAlertServiceTest extends KernelTestCase
{
    use FeatureFlagTrait;
    use MarketTestTrait;

    private WishlistService $wishlist;

    private DiscordUser $seller;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('cache.app')->clear();
        $this->wishlist = self::getContainer()->get(WishlistService::class);
        $this->bootMarketFixtures();
        $this->seller = $this->createUser('seller');
    }

    public function testListingAWishedCardAlertsTheWisherByNameAndNeverTheSeller(): void
    {
        $wisher = $this->createUser('wisher');
        $bystander = $this->createUser('bystander');
        $card = $this->createCard('Wanted');
        $this->giveCards($this->seller, $card, 1);
        $this->giveCards($wisher, $card, 1);
        $this->wishlist->add($wisher, $card);
        // the seller wishes it too (second copy): still never alerted about their own listing
        $this->wishlist->add($this->seller, $card);

        $this->list($card, 40);

        $notifications = $this->alertsFor($wisher);
        $this->assertCount(1, $notifications);
        $this->assertSame($card->getName(), $notifications[0]->getPayload()['cardName']);
        $this->assertSame(40, $notifications[0]->getPayload()['price']);
        $this->assertSame([], $this->alertsFor($this->seller));
        $this->assertSame([], $this->alertsFor($bystander));
        $this->assertFalse($notifications[0]->isBroadcast());
    }

    public function testAWatchedUniverseAlertsPlayersMissingTheCardWithoutNamingIt(): void
    {
        $missing = $this->createUser('missing');
        $owner = $this->createUser('owner');
        $card = $this->createCard('Secret');
        $this->giveCards($this->seller, $card, 1);
        $this->giveCards($owner, $card, 1);
        $this->wishlist->watchUniverse($missing, $this->extension);
        $this->wishlist->watchUniverse($owner, $this->extension);

        $this->list($card, 12);

        $notifications = $this->alertsFor($missing);
        $this->assertCount(1, $notifications);
        $this->assertNull($notifications[0]->getPayload()['cardName']);
        $this->assertSame($this->extension->getName(), $notifications[0]->getPayload()['universe']);
        $this->assertStringNotContainsString($card->getName(), (string) json_encode($notifications[0]->getPayload()));
        $this->assertSame([], $this->alertsFor($owner), 'Owning the card means nothing is missing.');
    }

    public function testDirectWishAndWatchedUniverseGiveASingleNamedAlert(): void
    {
        $player = $this->createUser('both');
        $card = $this->createCard('Both ways');
        $this->giveCards($this->seller, $card, 1);
        $this->giveCards($player, $card, 1);
        $this->wishlist->add($player, $card);
        $this->wishlist->watchUniverse($player, $this->extension);

        $this->list($card, 5);

        $notifications = $this->alertsFor($player);
        $this->assertCount(1, $notifications);
        $this->assertSame($card->getName(), $notifications[0]->getPayload()['cardName']);
    }

    public function testAReopenedListingNeverAlertsTheSamePlayerTwice(): void
    {
        $wisher = $this->createUser('wisher');
        $latecomer = $this->createUser('latecomer');
        $card = $this->createCard('Reopened');
        $this->giveCards($this->seller, $card, 1);
        $this->giveCards($wisher, $card, 1);
        $this->wishlist->add($wisher, $card);
        $listing = $this->list($card, 9);
        $alerts = self::getContainer()->get(WishlistAlertService::class);

        $this->assertSame(0, $alerts->alertListing($listing), 'Same listing, same player: already alerted.');

        $this->giveCards($latecomer, $card, 1);
        $this->wishlist->add($latecomer, $card);
        $this->assertSame(1, $alerts->alertListing($listing), 'A player who started wishing since is alerted once.');
        $this->assertCount(1, $this->alertsFor($wisher));
        $this->assertCount(2, $this->entityManager->getRepository(WishlistAlert::class)->findAll());
    }

    public function testAClosedListingAlertsNobody(): void
    {
        $wisher = $this->createUser('wisher');
        $card = $this->createCard('Gone');
        $this->giveCards($this->seller, $card, 1);
        $this->giveCards($wisher, $card, 1);
        $listing = $this->list($card, 9);
        $listing->close(MarketListingStatusEnum::WITHDRAWN, new \DateTimeImmutable());
        $this->wishlist->add($wisher, $card);

        $this->assertSame(0, self::getContainer()->get(WishlistAlertService::class)->alertListing($listing));
        $this->assertSame([], $this->alertsFor($wisher));
    }

    public function testNothingIsSentWhileTheFeatureIsOff(): void
    {
        $wisher = $this->createUser('wisher');
        $card = $this->createCard('Off');
        $this->giveCards($this->seller, $card, 1);
        $this->giveCards($wisher, $card, 1);
        $this->wishlist->add($wisher, $card);
        $this->setFeature(FeatureEnum::WISHLIST, false);

        $this->list($card, 9);

        $this->assertSame([], $this->alertsFor($wisher));
        $this->assertSame([], $this->entityManager->getRepository(WishlistAlert::class)->findAll());
    }

    private function list(\App\Entity\Card $card, int $price): \App\Entity\MarketListing
    {
        return self::getContainer()->get(MarketListingService::class)->create($this->seller, $card, false, $price);
    }

    /** @return list<Notification> */
    private function alertsFor(DiscordUser $player): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy(['recipient' => $player, 'type' => NotificationTypeEnum::WISHLIST_LISTED]);
    }
}
