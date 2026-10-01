<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\Admin\CoinRarityPrice;
use App\Entity\Booster;
use App\Entity\BoosterPurchase;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Entity\UniverseCompletionReward;
use App\Enum\Admin\EconomyPeriodEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Market\MarketListingStatusEnum;
use App\Service\Admin\EconomyStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class CoinEconomyStatsTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const int SCALE = 100_000_000;

    private EntityManagerInterface $entityManager;

    private EconomyStatsProvider $provider;

    private DiscordUser $alice;

    private DiscordUser $bob;

    private Extension $extension;

    private Booster $named;

    private Booster $unnamed;

    private Card $common;

    private Card $rare;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        // DST switch day: the 7-day period starts 2026-03-22 23:00 UTC
        self::mockTime(new \DateTimeImmutable('2026-03-29 10:00:00', new \DateTimeZone('UTC')));

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE discord_user, extension CASCADE');
        $this->provider = self::getContainer()->get(EconomyStatsProvider::class);

        $this->createDataset();
    }

    public function testBoosterPurchasesPerDayAndBreakdown(): void
    {
        $coin = $this->provider->getDashboard(EconomyPeriodEnum::WEEK)->coin;

        $this->assertSame(1, $coin->boosterPurchasesPerDay['2026-03-23']);
        $this->assertSame(10, $coin->boosterCoinsPerDay['2026-03-23']);
        $this->assertSame(1, $coin->boosterPurchasesPerDay['2026-03-29'], '28/03 23:30 UTC is 29/03 in Paris');
        $this->assertSame(20, $coin->boosterCoinsPerDay['2026-03-29']);
        $this->assertSame(2, array_sum($coin->boosterPurchasesPerDay), 'pending, failed and out-of-period purchases are excluded');

        $this->assertCount(2, $coin->boosterBreakdown);
        $this->assertSame('Extension Coin', $coin->boosterBreakdown[0]->label, 'sorted by coins, falls back to the extension name');
        $this->assertSame(20, $coin->boosterBreakdown[0]->coins);
        $this->assertSame('Pack Or', $coin->boosterBreakdown[1]->label);
        $this->assertSame(1, $coin->boosterBreakdown[1]->count);
    }

    public function testRewardsPerDayCountOnlyPaidOnes(): void
    {
        $coin = $this->provider->getDashboard(EconomyPeriodEnum::WEEK)->coin;

        $this->assertSame(1, $coin->rewardsPerDay['2026-03-24']);
        $this->assertSame(30, $coin->rewardCoinsPerDay['2026-03-24']);
        $this->assertSame(1, array_sum($coin->rewardsPerDay), 'pending, failed and cancelled rewards (paid ones included) are excluded');
    }

    public function testMarketSalesVolumeFeesAndRarityPrices(): void
    {
        $coin = $this->provider->getDashboard(EconomyPeriodEnum::WEEK)->coin;

        $this->assertSame(1, $coin->marketSalesPerDay['2026-03-25']);
        $this->assertSame(1, $coin->marketSalesPerDay['2026-03-29'], 'a transferred card is a sale even before the seller payout');
        $this->assertSame(2, array_sum($coin->marketSalesPerDay), 'refunded, failed and pending purchases are not sales');
        $this->assertSame(300, array_sum($coin->marketVolumePerDay));
        $this->assertSame(15 * self::SCALE, $coin->marketFeesMinor());
        $this->assertSame(1, $coin->activeListings);

        $prices = array_column(array_map(static fn (CoinRarityPrice $price): array => [$price->key, $price], $coin->marketRarityPrices), 1, 0);
        $this->assertSame(100.0, $prices['common']->averagePrice);
        $this->assertSame(200.0, $prices['rare']->averagePrice);
        $this->assertSame(0, $prices['legendary']->sales);
    }

    public function testBankFlows(): void
    {
        $coin = $this->provider->getDashboard(EconomyPeriodEnum::WEEK)->coin;

        // in: 10 + 20 purchases, market payments 100 + 200 + 60 (refunded) + 90 (refund pending)
        $this->assertSame(480 * self::SCALE, $coin->bankInMinor());
        // out: reward 30 (cancelled rewards excluded), payout 100 - 5, refund 60
        $this->assertSame(185 * self::SCALE, $coin->bankOutMinor());
        $this->assertSame(295 * self::SCALE, $coin->bankNetMinor());
        $this->assertSame(60 * self::SCALE, $coin->bankOutMinorPerDay['2026-03-26']);
    }

    public function testAlertsCountTheUnsettledRows(): void
    {
        $alerts = $this->provider->getDashboard(EconomyPeriodEnum::WEEK)->coin->alerts;

        $this->assertSame(1, $alerts->pendingBoosterPurchases);
        $this->assertSame(1, $alerts->failedBoosterPurchases);
        $this->assertSame(1, $alerts->pendingRewards);
        $this->assertSame(1, $alerts->failedRewards);
        $this->assertSame(1, $alerts->paymentPendingSales);
        $this->assertSame(1, $alerts->payoutPendingSales);
        $this->assertSame(1, $alerts->refundPendingSales);
        $this->assertSame(7, $alerts->total(), 'cancelled rewards never raise an alert');
    }

    public function testCoinActionsMakePlayersActive(): void
    {
        $this->assertSame(2, $this->provider->getKpis()->activePlayers7Days, 'alice buys and sells, bob buys on the market');
    }

    private function createDataset(): void
    {
        $this->alice = $this->user('alice');
        $this->bob = $this->user('bob');

        $extension = $this->extension = new Extension()->setName('Extension Coin')->setDescription('Coin')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($extension);
        $this->common = $this->card($extension, CardRarityEnum::COMMON);
        $this->rare = $this->card($extension, CardRarityEnum::RARE);
        $this->named = $this->booster($extension, 'Pack Or');
        $this->unnamed = $this->booster($extension, null);

        $this->purchase($this->named, 10, '2026-03-23 10:00:00', 'complete');
        $this->purchase($this->named, 10, '2026-03-22 22:30:00', 'complete');
        $this->purchase($this->unnamed, 20, '2026-03-28 23:30:00', 'complete');
        $this->purchase($this->named, 10, '2026-03-25 10:00:00', null);
        $this->purchase($this->named, 10, '2026-03-25 11:00:00', 'fail');

        $this->reward(30, '2026-03-24 10:00:00', 'pay');
        $this->reward(50, '2026-03-24 11:00:00', null);
        $this->reward(40, '2026-03-24 12:00:00', 'fail');
        $this->reward(70, '2026-03-22 22:30:00', 'pay');
        $this->reward(90, '2026-03-24 13:00:00', 'cancelPaid');
        $this->reward(60, '2026-03-24 14:00:00', 'cancel');

        $this->sale($this->common, 100, 5, '2026-03-25 10:00:00', 'complete');
        $this->sale($this->rare, 200, 10, '2026-03-28 23:30:00', 'transfer');
        $this->sale($this->common, 60, 3, '2026-03-26 10:00:00', 'refund');
        $this->sale($this->common, 70, 3, '2026-03-26 11:00:00', null);
        $this->sale($this->common, 80, 4, '2026-03-26 12:00:00', 'fail');
        $this->sale($this->common, 90, 4, '2026-03-27 10:00:00', 'refundPending');
        $this->entityManager->persist(new MarketListing($this->alice, $this->common, false, 50));

        $this->entityManager->flush();
    }

    private function user(string $name): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId('coin-' . $name)->setUsername($name);
        $this->entityManager->persist($user);

        return $user;
    }

    private function card(Extension $extension, CardRarityEnum $rarity): Card
    {
        $card = new Card()->setName('Coin ' . $rarity->value)->setDescription('Coin')->setStatus(CardStatusEnum::PUBLISHED)->setRarity($rarity)->setExtension($extension);
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        return $card;
    }

    private function booster(Extension $extension, ?string $name): Booster
    {
        $booster = new Booster()->setExtension($extension)->setName($name)->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]]);
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        return $booster;
    }

    private function purchase(Booster $booster, int $price, string $atUtc, ?string $outcome): void
    {
        $purchase = new BoosterPurchase($this->alice, $booster, $price, $this->utc($atUtc));

        match ($outcome) {
            'complete' => $purchase->complete('tx', $this->utc($atUtc)),
            'fail' => $purchase->fail('refused', $this->utc($atUtc)),
            default => null,
        };
        $this->entityManager->persist($purchase);
    }

    private function reward(int $amount, string $atUtc, ?string $outcome): void
    {
        $reward = new UniverseCompletionReward($this->user('winner-' . $amount), $this->extension, $amount, $this->utc($atUtc));

        if (\in_array($outcome, ['pay', 'cancelPaid'], true)) {
            $reward->markPaid('tx', $this->utc($atUtc));
        }

        match ($outcome) {
            'fail' => $reward->markFailed(),
            'cancel', 'cancelPaid' => $reward->markCancelled(),
            default => null,
        };
        $this->entityManager->persist($reward);
    }

    private function sale(Card $card, int $price, int $feeCoins, string $requestedAtUtc, ?string $outcome): void
    {
        $listing = new MarketListing($this->alice, $card, false, $price);
        $listing->close(MarketListingStatusEnum::SOLD, $this->utc($requestedAtUtc));
        $this->entityManager->persist($listing);

        $purchase = new MarketPurchase($listing, $this->bob, $this->alice, $price, $feeCoins * self::SCALE, $this->utc($requestedAtUtc));
        // resolved a little after the request, on the same Paris day
        $resolvedAt = $this->utc($requestedAtUtc)->modify('+1 hour');

        match ($outcome) {
            'complete' => $this->completeSale($purchase, $resolvedAt),
            'transfer' => $purchase->markCardTransferred('pay'),
            'refund' => $this->refundSale($purchase, $resolvedAt),
            'refundPending' => $purchase->markRefundPending('pay', 'seller lost the copy'),
            'fail' => $purchase->fail('refused', $resolvedAt),
            default => null,
        };
        $this->entityManager->persist($purchase);
    }

    private function completeSale(MarketPurchase $purchase, \DateTimeImmutable $at): void
    {
        $purchase->markCardTransferred('pay');
        $purchase->complete('payout', $at);
    }

    private function refundSale(MarketPurchase $purchase, \DateTimeImmutable $at): void
    {
        $purchase->markRefundPending('pay', 'seller lost the copy');
        $purchase->refund('refund', $at);
    }

    private function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }
}
