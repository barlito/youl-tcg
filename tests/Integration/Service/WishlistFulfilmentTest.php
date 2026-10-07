<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\TradeLineRequest;
use App\Entity\Booster;
use App\Entity\UserBooster;
use App\Entity\WishlistEntry;
use App\Enum\FeatureEnum;
use App\Service\Booster\BoosterOpeningService;
use App\Service\Market\MarketListingService;
use App\Service\Market\MarketPurchaseService;
use App\Service\Trade\TradeOfferService;
use App\Service\Wishlist\WishlistService;
use App\Tests\FeatureFlagTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WishlistFulfilmentTest extends KernelTestCase
{
    use FeatureFlagTrait;
    use MarketTestTrait;

    private WishlistService $wishlist;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('cache.app')->clear();
        $this->wishlist = self::getContainer()->get(WishlistService::class);
        $this->bootMarketFixtures();
    }

    public function testAnOpeningDropsTheWishesOfTheDrawnCardsOnly(): void
    {
        $player = $this->createUser('opener');
        $booster = new Booster()->setExtension($this->extension)->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]]);
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);
        $this->entityManager->persist(new UserBooster()->setDiscordUser($player)->setBooster($booster)->setQuantity(1));
        $first = $this->createCard('First');
        $second = $this->createCard('Second');
        $this->giveCards($player, $first, 1);
        $this->giveCards($player, $second, 1);
        $this->wishlist->add($player, $first);
        $this->wishlist->add($player, $second);

        $result = self::getContainer()->get(BoosterOpeningService::class)->open($player, $booster);

        $drawnId = (string) $result->drawnCards[0]->card->getId();
        $remaining = array_keys($this->wishlist->wishedCardIds($player));
        $this->assertNotContains($drawnId, $remaining);
        $this->assertCount(\in_array($drawnId, [(string) $first->getId(), (string) $second->getId()], true) ? 1 : 2, $remaining);
    }

    public function testReceivingAWishedCardThroughATradeRemovesTheWish(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceCard = $this->createCard('Alice card');
        $bobCard = $this->createCard('Bob card');
        $this->giveCards($alice, $aliceCard, 1);
        $this->giveCards($bob, $bobCard, 1);
        $this->entityManager->persist(new WishlistEntry($alice, $bobCard));
        $this->entityManager->flush();
        $trades = self::getContainer()->get(TradeOfferService::class);
        $offer = $trades->create($alice, $bob, [new TradeLineRequest($aliceCard, 1)], [new TradeLineRequest($bobCard, 1)]);

        $trades->accept($offer, $bob);

        $this->assertSame([], $this->wishlist->wishedCardIds($alice));
    }

    public function testBuyingAWishedCardOnTheMarketRemovesTheWish(): void
    {
        $seller = $this->createUser('seller');
        $buyer = $this->createUser('buyer');
        $card = $this->createCard('Bought');
        $this->giveCards($seller, $card, 1);
        $listing = self::getContainer()->get(MarketListingService::class)->create($seller, $card, false, 30);
        $this->wishlist->add($buyer, $card);
        $this->assertCount(1, $this->wishlist->wishedCardIds($buyer));

        self::getContainer()->get(MarketPurchaseService::class)->purchase($buyer, $listing, 'buyer-jwt');

        $this->assertSame([], $this->wishlist->wishedCardIds($buyer));
    }

    public function testWishesAreFrozenWhileTheFeatureIsOff(): void
    {
        $player = $this->createUser('opener');
        $card = $this->createCard('Frozen');
        $this->giveCards($player, $card, 1);
        $this->wishlist->add($player, $card);
        $this->setFeature(FeatureEnum::WISHLIST, false);

        $this->wishlist->fulfil($player, [$card]);
        $this->setFeature(FeatureEnum::WISHLIST, true);

        $this->assertCount(1, $this->wishlist->wishedCardIds($player));
    }
}
