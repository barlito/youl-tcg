<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Admin\AdminApiScopeEnum;
use App\Enum\Admin\EconomyPeriodEnum;
use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\EconomyStatsProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class StatsApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use ImportApiTestTrait;
    use StatsApiScenarioTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));
        $this->seedScenario();
    }

    public function testEverySectionAndTopLevelKeyIsPresent(): void
    {
        $body = $this->stats();

        self::assertResponseStatusCodeSame(200);
        $this->assertSame(StatsSectionEnum::values(), array_keys($body));

        $expected = [
            'meta' => ['generatedAt', 'period', 'periodStart', 'periodEnd', 'timezone', 'cacheTtlSeconds', 'version', 'commit', 'features', 'coinSettings', 'gameRules', 'sections'],
            'catalogue' => ['extensions', 'cards'],
            'boosters' => ['boosters'],
            'draws' => ['rarities', 'perExtension', 'holoPerBooster', 'uniquesDrawn'],
            'players' => ['count', 'players'],
            'activity' => ['activePlayersPerDay', 'openingsPerDay', 'current', 'newPlayersPerDay', 'registrationsPerDay', 'retentionCohorts'],
            'economy' => ['note', 'boosterPurchases', 'universeRewards', 'marketSales', 'bank', 'boostersPerChannel', 'alerts'],
            'market' => ['listingsByStatus', 'activeListings', 'sales', 'purchasesByStatus', 'topCards', 'topSellers', 'topBuyers'],
            'trades' => ['offersByStatus', 'perDay', 'acceptanceRatePercent', 'lines', 'mostOfferedCards', 'mostRequestedCards', 'mostActivePlayers'],
            'recycling' => ['totals', 'perWeek', 'perDay', 'boostersProduced', 'copiesByRarity', 'topCards'],
            'fusion' => ['totals', 'perDay', 'topCards', 'topPlayers'],
            'codes' => ['totals', 'batches', 'usesPerDay'],
            'streaks' => ['rewards', 'boostersChosen', 'currentStreaks'],
            'universeRewards' => ['byStatus', 'paidDelaySeconds', 'completionsPerDay', 'perExtension'],
            'notifications' => ['byType', 'broadcasts', 'announcements', 'note'],
        ];

        foreach ($expected as $section => $keys) {
            $this->assertSame($keys, array_keys($body[$section]), $section);
        }
    }

    public function testMetaExposesFlagsSettingsAndPeriod(): void
    {
        $meta = $this->stats(['meta'])['meta'];

        $this->assertSame('2026-10-07T10:00:00Z', $meta['generatedAt']);
        $this->assertSame(30, $meta['period']);
        $this->assertSame('2026-09-08', $meta['periodStart']);
        $this->assertSame('2026-10-07', $meta['periodEnd']);
        $this->assertSame('Europe/Paris', $meta['timezone']);
        $this->assertSame(['trades', 'recycling', 'universe_rewards', 'fusion'], array_keys($meta['features']));
        $this->assertSame(['minor' => '50000000000', 'coins' => 500], $meta['coinSettings']['defaultUniverseRewardCoins']);
        $this->assertSame(5, $meta['coinSettings']['marketFeePercent']);
        $this->assertNull($meta['commit']);
        $this->assertSame(10, $meta['gameRules']['recycleBoosterCostPoints']);
        $this->assertSame(10, $meta['gameRules']['fusionCostCopies']);
        $this->assertSame(10, $meta['gameRules']['fusionMaxPerOperation']);
    }

    public function testSectionsParameterFiltersTheAnswer(): void
    {
        $body = $this->stats(['meta', 'codes']);

        $this->assertSame(['meta', 'codes'], array_keys($body));

        $body = $this->stats(['streaks']);
        $this->assertSame(['streaks'], array_keys($body));
    }

    public function testInvalidPeriodAndUnknownSectionAreUnprocessable(): void
    {
        $body = $this->rawStats('period=15');
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('period', $body['violations']);

        $body = $this->rawStats('period=abc');
        self::assertResponseStatusCodeSame(422);

        $body = $this->rawStats('sections=meta,nope');
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('sections', $body['violations']);
        $this->assertStringContainsString('nope', $body['violations']['sections']);
    }

    public function testPeriodChangesTheWindow(): void
    {
        $meta = $this->stats(['meta'], 7)['meta'];

        $this->assertSame(7, $meta['period']);
        $this->assertSame('2026-10-01', $meta['periodStart']);
    }

    public function testTheAnswerIsCachedPerPeriodAndSections(): void
    {
        $first = $this->stats(['codes']);
        $this->conn->executeStatement("UPDATE booster_code SET uses = 5 WHERE code = 'STATSCODE001'");

        $this->assertSame($first, $this->stats(['codes']), 'served from cache');

        static::getContainer()->get('cache.app')->clear();
        $this->assertNotSame($first, $this->stats(['codes']));
    }

    public function testAPlayerWithKnownActivityHasExactCounters(): void
    {
        $player = $this->player(self::STATSY);

        $this->assertSame('Statsy', $player['username']);
        $this->assertSame('2026-09-01T10:00:00Z', $player['registeredAt']);
        $this->assertSame('2026-06-01T10:00:00Z', $player['firstActivityAt']);
        $this->assertSame('2026-10-07T09:15:00Z', $player['lastActivityAt']);
        $this->assertSame(['period' => 3, 'total' => 4], $player['activeDays']);
        $this->assertSame(['total' => 4, 'period' => 3], $player['openings']);
        $this->assertSame(['total' => 2, 'period' => 2], $player['claims']);
        $this->assertSame(['total' => 1, 'period' => 1], $player['boosterPurchases']['completed']);
        $this->assertSame(['total' => 1, 'period' => 1], $player['boosterPurchases']['failed']);
        $this->assertSame(['minor' => '5000000000', 'coins' => 50], $player['boosterPurchases']['coinsSpent']['total']);
        $this->assertSame(['total' => 1, 'period' => 1], $player['codesUsed']['redemptions']);
        $this->assertSame(['total' => 2, 'period' => 2], $player['codesUsed']['boostersGranted']);
        $this->assertSame(['distinct' => 2, 'copies' => 4, 'holoCopies' => 1, 'uniquesHeld' => 0], $player['cards']);
        $this->assertSame(28.57, $player['completion']['globalPercent']);
        $this->assertSame(['extension' => 'cyberpunk-2077', 'owned' => 2, 'total' => 6, 'percent' => 33.33], $player['completion']['universes'][0]);
        $this->assertSame(['total' => 1, 'period' => 1], $player['market']['sales']['count']);
        $this->assertSame(100, $player['market']['sales']['volume']['total']['coins']);
        $this->assertSame(['minor' => '500000000', 'coins' => 5], $player['market']['sales']['feesPaid']['total']);
        $this->assertSame(['total' => 1, 'period' => 1], $player['market']['purchases']['count']);
        $this->assertSame(40, $player['market']['purchases']['volume']['total']['coins']);
        $this->assertSame(1, $player['market']['activeListings']);
        $this->assertSame(1, $player['market']['engagedListings']);
        $this->assertSame(2, $player['trades']['proposed']['total']);
        $this->assertSame(1, $player['trades']['received']['total']);
        $this->assertSame(1, $player['trades']['accepted']['total']);
        $this->assertSame(1, $player['trades']['refusedByPlayer']['total']);
        $this->assertSame(0, $player['trades']['proposedAndRefused']['total']);
        $this->assertSame(1, $player['recycling']['operations']['total']);
        $this->assertSame(9, $player['recycling']['points']['total']);
        $this->assertSame(1, $player['recycling']['boostersObtained']['total']);
        $this->assertSame(['operations' => ['total' => 2, 'period' => 1], 'fusions' => ['total' => 3, 'period' => 2], 'copiesConsumed' => ['total' => 30, 'period' => 20], 'holosCreated' => ['total' => 3, 'period' => 2]], $player['fusion']);
        $this->assertSame(['current' => 3, 'best' => 3, 'startedOn' => '2026-10-05', 'openedToday' => true, 'milestonesAwarded' => 1, 'rewardsChosen' => 1], $player['streak']);
        $this->assertSame(1, $player['universeRewards']['paid']['count']);
        $this->assertSame(500, $player['universeRewards']['paid']['amount']['coins']);
        $this->assertSame(['personal' => 1, 'broadcast' => 2, 'total' => 3], $player['unreadNotifications']);
        $this->assertSame(['copies' => 4, 'distinct' => 1], $player['unopenedBoosters']);
    }

    public function testCollectorHoldsAUniqueAndHasNoStreakLeft(): void
    {
        $player = $this->player(self::COLLECTOR);

        $this->assertSame(1, $player['cards']['uniquesHeld']);
        $this->assertSame(0, $player['streak']['current']);
        $this->assertSame(1, $player['streak']['best']);
    }

    public function testCatalogueCardCountersMatchTheScenario(): void
    {
        $catalogue = $this->stats(['catalogue'])['catalogue'];
        $cards = array_column($catalogue['cards'], null, 'name');

        $common = $cards['Cyberpunk Barlito'];
        $this->assertSame(['total' => 4, 'holo' => 1, 'period' => 3, 'firstAt' => '2026-06-01T10:00:00Z', 'lastAt' => '2026-10-06T09:00:00Z'], $common['draws']);
        $this->assertSame(1, $common['activeListings']);
        $this->assertSame(1, $common['marketSales']['count']);
        $this->assertSame(40, $common['marketSales']['minPriceCoins']);
        $this->assertSame(1, $common['timesTraded']);
        $this->assertSame(2, $common['copiesRecycled']);

        $rare = $cards['Cyberpunk Rogue'];
        $this->assertSame(2, $rare['draws']['total']);
        $this->assertSame(1, $rare['draws']['holo']);
        $this->assertSame(2, $rare['holders']);
        $this->assertSame(['total' => 2, 'normal' => 2, 'holo' => 0], $rare['copiesInCirculation']);
        $this->assertSame(100, $rare['marketSales']['maxPriceCoins']);
        $this->assertSame(100.0, $rare['marketSales']['averagePriceCoins']);

        $unique = $cards['Cyberpunk Farf'];
        $this->assertTrue($unique['unique']);
        $this->assertSame(['discordId' => self::COLLECTOR, 'username' => 'Collectionneur'], $unique['claimedBy']);

        $johnny = $cards['Cyberpunk Johnny Silverhand'];
        $this->assertTrue($johnny['alwaysHolo']);
        $this->assertFalse($johnny['hasMask']);

        $extensions = array_column($catalogue['extensions'], null, 'slug');
        $this->assertSame(['total' => 2, 'published' => 2, 'drawn' => 1], $extensions['cyberpunk-2077']['uniques']);
        $this->assertSame(['published' => 2, 'draft' => 0], $extensions['cyberpunk-2077']['cardsByRarityAndStatus']['rare']);
        $this->assertSame(['published' => 0, 'draft' => 1], $extensions['bleach']['cardsByRarityAndStatus']['rare']);
        $this->assertSame(1, $extensions['cyberpunk-2077']['boosters']);
    }

    public function testBoosterChannelCounters(): void
    {
        $boosters = $this->stats(['boosters'])['boosters']['boosters'];
        $cyberpunk = array_values(array_filter($boosters, static fn (array $booster): bool => 'cyberpunk-2077' === $booster['extension']['slug']))[0];

        $this->assertSame(3, $cyberpunk['cardCount']);
        $this->assertSame(['total' => 2, 'period' => 2], $cyberpunk['channels']['claims']);
        $this->assertSame(['total' => 5, 'period' => 4], $cyberpunk['channels']['openings']);
        $this->assertSame(['total' => 1, 'period' => 1], $cyberpunk['channels']['purchases']['completed']);
        $this->assertSame(['total' => 1, 'period' => 1], $cyberpunk['channels']['purchases']['failed']);
        $this->assertSame(50, $cyberpunk['channels']['purchaseCoinsSpent']['total']['coins']);
        $this->assertSame(['total' => 2, 'period' => 2], $cyberpunk['channels']['codeBoostersGranted']);
        $this->assertSame(['total' => 1, 'period' => 1], $cyberpunk['channels']['recycleBoostersGranted']);
        $this->assertSame(['total' => 1, 'period' => 1], $cyberpunk['channels']['streakRewardsChosen']);
        $inventory = $this->conn->fetchAssociative('SELECT SUM(quantity) AS copies, COUNT(*) AS holders FROM user_booster WHERE booster_id = ?', [$this->ids['booster']]);
        $this->assertSame(['copies' => (int) $inventory['copies'], 'holders' => (int) $inventory['holders']], $cyberpunk['unopenedInInventories']);
        $this->assertGreaterThanOrEqual(4, $cyberpunk['unopenedInInventories']['copies']);
        $this->assertSame(35, $cyberpunk['rarityRates'][2]['holoChance']);
        $this->assertSame(['common' => 55.0, 'rare' => 40.0, 'legendary' => 5.0], $cyberpunk['dropRates'][2]['rates']);
        $this->assertNull($cyberpunk['purchasePrice']);
    }

    public function testDrawsMatchTheDashboard(): void
    {
        $draws = $this->stats(['draws'])['draws'];
        $dashboard = static::getContainer()->get(EconomyStatsProvider::class)->getDashboard(EconomyPeriodEnum::MONTH);

        $this->assertSame($dashboard->rarityComparison->openingCount, $draws['rarities']['openings']);
        $this->assertSame($dashboard->rarityComparison->cardCount, $draws['rarities']['cards']);

        foreach ($dashboard->rarityComparison->rows as $index => $row) {
            $bucket = $draws['rarities']['buckets'][$index];
            $this->assertSame($row->key, $bucket['key']);
            $this->assertSame($row->observedCount, $bucket['observedCount']);
            $this->assertSame($row->observedShare, $bucket['observedSharePercent']);
            $this->assertSame($row->expectedShare, $bucket['expectedSharePercent']);
        }

        $this->assertSame($dashboard->rarityComparison->holo->observedShare, $draws['rarities']['holo']['observedSharePercent']);
        $buckets = $draws['perExtension'][0]['byBucket'];
        ksort($buckets);
        $this->assertSame(['common' => 3, 'rare' => 2, 'unique' => 1], $buckets);
        $this->assertSame(6, $draws['perExtension'][0]['cards']);
        $this->assertSame(2, $draws['perExtension'][0]['holos']);
        $this->assertSame(4, $draws['perExtension'][0]['openings']);
    }

    public function testUniqueDrawnNamesTheDrawerAndTheDate(): void
    {
        $uniques = $this->stats(['draws'])['draws']['uniquesDrawn'];
        $farf = array_values(array_filter($uniques, static fn (array $row): bool => 'Cyberpunk Farf' === $row['card']['name']))[0];

        $this->assertSame(['discordId' => self::COLLECTOR, 'username' => 'Collectionneur'], $farf['drawnBy']);
        $this->assertSame('2026-10-04T12:00:00Z', $farf['drawnAt']);
        $this->assertSame(self::COLLECTOR, $farf['currentHolder']['discordId']);
    }

    public function testActivityMatchesTheDashboardAndComputesCohorts(): void
    {
        $activity = $this->stats(['activity'])['activity'];
        $dashboard = static::getContainer()->get(EconomyStatsProvider::class)->getDashboard(EconomyPeriodEnum::MONTH);

        $this->assertSame($dashboard->activePlayersPerDay, array_column($activity['activePlayersPerDay'], 'value', 'day'));
        $this->assertSame($dashboard->openingsPerDay, array_column($activity['openingsPerDay'], 'value', 'day'));
        $this->assertSame(2, array_column($activity['activePlayersPerDay'], 'value', 'day')['2026-10-07']);
        $this->assertSame(['dau' => 2, 'wau' => 3, 'mau' => 3], \array_slice($activity['current'], 0, 3));
        $this->assertSame(9, $activity['current']['registeredPlayers']);

        $newPlayers = array_column($activity['newPlayersPerDay'], 'value', 'day');
        $this->assertSame(1, $newPlayers['2026-10-04']);
        $this->assertSame(1, $newPlayers['2026-10-05']);
        $this->assertSame(2, array_sum($newPlayers));

        $registrations = array_column($activity['registrationsPerDay'], 'value', 'day');
        $this->assertSame(1, $registrations['2026-09-20'] ?? 0);

        $cohorts = array_column($activity['retentionCohorts'], null, 'week');
        $this->assertSame(['2026-09-28', '2026-10-05'], array_keys($cohorts));
        $this->assertSame(['eligible' => 1, 'retained' => 0, 'ratePercent' => 0.0], $cohorts['2026-09-28']['retention']['d1']);
        $this->assertSame(['eligible' => 1, 'retained' => 1, 'ratePercent' => 100.0], $cohorts['2026-10-05']['retention']['d1']);
        $this->assertNull($cohorts['2026-10-05']['retention']['d7']['ratePercent']);
    }

    public function testEconomyReusesTheDashboardCoinBlock(): void
    {
        $economy = $this->stats(['economy'])['economy'];
        $coin = static::getContainer()->get(EconomyStatsProvider::class)->getDashboard(EconomyPeriodEnum::MONTH)->coin;

        $this->assertSame($coin->boosterPurchasesPerDay, array_column($economy['boosterPurchases']['perDay'], 'count', 'day'));
        $this->assertSame(['count' => 1, 'coins' => ['minor' => '5000000000', 'coins' => 50]], array_intersect_key(array_column($economy['boosterPurchases']['perDay'], null, 'day')['2026-10-06'], ['count' => 0, 'coins' => 0]));
        $this->assertSame(500, array_column($economy['universeRewards']['perDay'], null, 'day')['2026-10-06']['coins']['coins']);
        $this->assertSame(140, $economy['marketSales']['volumeTotal']['coins']);
        $this->assertSame(['minor' => '700000000', 'coins' => 7], $economy['marketSales']['feesTotal']);
        $this->assertSame($coin->bankInMinor(), (int) $economy['bank']['in']['minor']);
        $this->assertSame(['minor' => '19000000000', 'coins' => 190], $economy['bank']['in']);
        $this->assertSame(['minor' => '59500000000', 'coins' => 595], $economy['bank']['out']);
        $this->assertSame(['minor' => '-40500000000', 'coins' => -405], $economy['bank']['net']);
        $this->assertSame(1, $economy['alerts']['paymentPendingSales']);
        $this->assertSame(1, $economy['alerts']['payoutPendingSales']);
        $this->assertSame(1, $economy['alerts']['pendingRewards']);
        $this->assertSame(1, $economy['alerts']['failedBoosterPurchases']);
        $this->assertSame(4, $economy['alerts']['total']);
        $this->assertSame(2, $economy['boostersPerChannel']['claim']['total']);
        $this->assertSame(2, $economy['boostersPerChannel']['code']['total']);
    }

    public function testMarketDistributionsDelaysAndRankings(): void
    {
        $market = $this->stats(['market'])['market'];

        $this->assertSame(['total' => 2, 'period' => 2], $market['listingsByStatus']['sold']);
        $this->assertSame(1, $market['listingsByStatus']['active']['total']);
        $this->assertSame(1, $market['listingsByStatus']['reserved_for_purchase']['total']);
        $this->assertSame(1, $market['purchasesByStatus']['completed']['total']);
        $this->assertSame(1, $market['purchasesByStatus']['card_transferred']['total']);
        $this->assertSame(1, $market['purchasesByStatus']['payment_pending']['total']);
        $this->assertSame(0, $market['purchasesByStatus']['refunded']['total']);

        $this->assertSame(1, $market['activeListings']['total']);
        $this->assertSame(20.0, $market['activeListings']['byRarity']['common']['normal']['median']);

        $price = $market['sales']['priceCoins']['allTime'];
        $this->assertSame(['count' => 2, 'min' => 40, 'median' => 70.0, 'max' => 100, 'average' => 70.0], $price);
        $this->assertSame(['count' => 2, 'average' => 14400.0, 'median' => 14400.0, 'max' => 21600.0], $market['sales']['delaySeconds']['allTime']);
        $this->assertSame(100.0, $market['sales']['priceCoinsByRarityAndFinish']['rare']['normal']['allTime']['median']);

        $topCards = $market['topCards']['allTime'];
        $this->assertSame('Cyberpunk Rogue', $topCards[0]['card']['name']);
        $this->assertSame(['minor' => '10000000000', 'coins' => 100], $topCards[0]['volume']);
        $this->assertSame(self::STATSY, $market['topSellers']['allTime'][0]['seller']['discordId']);
        $this->assertSame(self::BUYER, $market['topBuyers']['allTime'][0]['buyer']['discordId']);
    }

    public function testTradesRatesLinesAndRankings(): void
    {
        $trades = $this->stats(['trades'])['trades'];

        $this->assertSame(['total' => 1, 'period' => 1], $trades['offersByStatus']['accepted']);
        $this->assertSame(['total' => 1, 'period' => 1], $trades['offersByStatus']['refused']);
        $this->assertSame(['total' => 1, 'period' => 1], $trades['offersByStatus']['pending']);
        $this->assertSame(50.0, $trades['acceptanceRatePercent']['period']);
        $this->assertSame(3, $trades['lines']['offers']);
        $this->assertSame(1.0, $trades['lines']['averageLinesPerOffer']);
        $this->assertSame(3, $trades['lines']['offered']['copies']);
        $this->assertSame(['day' => '2026-10-05', 'created' => 1, 'accepted' => 1, 'refused' => 0, 'cancelled' => 0, 'invalidated' => 0], array_column($trades['perDay'], null, 'day')['2026-10-05']);
        $this->assertSame('Cyberpunk Rogue', $trades['mostOfferedCards']['allTime'][0]['card']['name']);
        $this->assertSame(self::STATSY, $trades['mostActivePlayers']['proposers']['allTime'][0]['player']['discordId']);
        $this->assertSame(2, $trades['mostActivePlayers']['proposers']['allTime'][0]['offers']);
    }

    public function testFusionSection(): void
    {
        $fusion = $this->stats(['fusion'])['fusion'];

        $this->assertSame(['operations' => 3, 'fusions' => 4, 'copiesConsumed' => 40, 'holosCreated' => 4, 'players' => 2], $fusion['totals']['allTime']);
        $this->assertSame(['operations' => 2, 'fusions' => 3, 'copiesConsumed' => 30, 'holosCreated' => 3, 'players' => 2], $fusion['totals']['period']);

        $perDay = array_column($fusion['perDay'], null, 'day');
        $this->assertSame(['day' => '2026-10-06', 'operations' => 1, 'fusions' => 2, 'copies' => 20, 'holos' => 2], $perDay['2026-10-06']);
        $this->assertSame(1, $perDay['2026-10-07']['fusions']);
        $this->assertSame(0, $perDay['2026-10-05']['operations']);

        $this->assertSame('Cyberpunk Barlito', $fusion['topCards']['allTime'][0]['card']['name']);
        $this->assertSame(3, $fusion['topCards']['allTime'][0]['fusions']);
        $this->assertSame(30, $fusion['topCards']['period'][0]['copiesConsumed']);
        $this->assertCount(1, $fusion['topCards']['period']);

        $this->assertSame(self::STATSY, $fusion['topPlayers']['allTime'][0]['player']['discordId']);
        $this->assertSame(3, $fusion['topPlayers']['allTime'][0]['fusions']);
        $this->assertSame(2, $fusion['topPlayers']['allTime'][0]['operations']);
        $this->assertSame(2, $fusion['topPlayers']['period'][0]['fusions']);
    }

    public function testRecyclingCodesStreaksAndUniverseRewards(): void
    {
        $body = $this->stats(['recycling', 'codes', 'streaks', 'universeRewards']);

        $this->assertSame(['operations' => 1, 'points' => 9, 'boosters' => 1, 'copies' => 4, 'holoCopies' => 1, 'players' => 1], $body['recycling']['totals']['allTime']);
        $this->assertSame(['normal' => 2, 'holo' => 0, 'points' => 6, 'periodNormal' => 2, 'periodHolo' => 0], $body['recycling']['copiesByRarity']['rare']);
        $this->assertSame(['normal' => 1, 'holo' => 1, 'points' => 3, 'periodNormal' => 1, 'periodHolo' => 1], $body['recycling']['copiesByRarity']['common']);
        $this->assertSame(1, $body['recycling']['boostersProduced'][0]['boosters']);
        $this->assertSame(1, array_column($body['recycling']['perDay'], null, 'day')['2026-10-06']['operations']);

        $batch = $body['codes']['batches'][0];
        $this->assertSame('LOT-A', $batch['batchLabel']);
        $this->assertSame(1, $batch['codes']);
        $this->assertSame(1, $batch['active']);
        $this->assertSame(5, $batch['maxUsesTotal']);
        $this->assertSame(20.0, $batch['usageRatePercent']);
        $this->assertSame(2, $batch['boostersGranted']);
        $this->assertSame(['day' => '2026-10-05', 'redemptions' => 1, 'boosters' => 2, 'players' => 1], array_column($body['codes']['usesPerDay'], null, 'day')['2026-10-05']);

        $this->assertSame(['awarded' => 1, 'chosen' => 1, 'pending' => 0, 'players' => 1], array_diff_key($body['streaks']['rewards'], ['perMilestone' => 0]));
        $this->assertSame(7, $body['streaks']['rewards']['perMilestone'][0]['milestone']);
        $this->assertSame(1, $body['streaks']['boostersChosen'][0]['chosen']);
        $this->assertSame(['playersWithOpenings' => 2, 'running' => 1, 'atRisk' => 0, 'bestEver' => 3, 'playersPerLength' => [['length' => 3, 'players' => 1]]], $body['streaks']['currentStreaks']);

        $this->assertSame(['count' => 1, 'amount' => ['minor' => '50000000000', 'coins' => 500]], $body['universeRewards']['byStatus']['paid']);
        $this->assertSame(300, $body['universeRewards']['byStatus']['pending']['amount']['coins']);
        $this->assertSame(['count' => 1, 'average' => 30.0, 'median' => 30.0, 'max' => 30.0], $body['universeRewards']['paidDelaySeconds']);
        $perExtension = array_column($body['universeRewards']['perExtension'], null, 'extension');
        $this->assertSame(1, $perExtension['cyberpunk-2077']['completions']);
        $this->assertSame(1, $perExtension['magic']['byStatus']['pending']['count']);
        $this->assertSame(0, $perExtension['bleach']['completions']);
    }

    public function testNotificationsCountReadsAndBroadcastRates(): void
    {
        $notifications = $this->stats(['notifications', 'players'])['notifications'];
        $players = $this->stats(['players'])['players']['count'];

        $credited = $notifications['byType']['booster_credited'];
        $this->assertSame(['total' => 2, 'read' => 1, 'unread' => 1, 'readRatePercent' => 50.0], $credited['personal']);

        $unique = $notifications['byType']['unique_pulled'];
        $this->assertSame(1, $unique['broadcasts']['total']);
        $this->assertSame(round(1 / $players * 100, 2), $unique['broadcasts']['readRatePercent']);

        $broadcast = array_values(array_filter($notifications['broadcasts'], static fn (array $row): bool => 'unique_pulled' === $row['type']))[0];
        $this->assertSame(['playerName' => 'Collectionneur'], $broadcast['payload']);
        $this->assertSame(1, $broadcast['readers']);

        $announcement = $notifications['announcements'][0];
        $this->assertSame('Hello', $announcement['title']);
        $this->assertSame('all', $announcement['target']);
        $this->assertSame('Barlito', $announcement['author']['username']);
        $this->assertSame(['readers' => 1, 'eligible' => $players, 'readRatePercent' => round(1 / $players * 100, 2)], $announcement['reads']);
    }

    public function testQueryCountDoesNotGrowWithThePlayerCount(): void
    {
        $before = $this->queryCount();

        for ($index = 0; $index < 20; ++$index) {
            $this->conn->insert('discord_user', ['discord_id' => (string) (910000000000000000 + $index), 'username' => 'bulk' . $index, 'roles' => '[]', 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00']);
        }

        static::getContainer()->get('cache.app')->clear();
        $this->assertSame($before, $this->queryCount());
    }

    /**
     * @param list<string>|null $sections
     *
     * @return array<string, mixed>
     */
    private function stats(?array $sections = null, ?int $period = null): array
    {
        $query = [];
        if (null !== $sections) {
            $query[] = 'sections=' . implode(',', $sections);
        }
        if (null !== $period) {
            $query[] = 'period=' . $period;
        }

        return $this->rawStats(implode('&', $query));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawStats(string $query): array
    {
        return $this->apiRequest($this->client, 'GET', '/api/admin/stats' . ('' === $query ? '' : '?' . $query), $this->newToken([AdminApiScopeEnum::STATS]));
    }

    /**
     * @return array<string, mixed>
     */
    private function player(string $discordId): array
    {
        $players = array_column($this->stats(['players'])['players']['players'], null, 'discordId');
        $this->assertArrayHasKey($discordId, $players);

        return $players[$discordId];
    }

    private function queryCount(): int
    {
        $this->client->enableProfiler();
        $this->rawStats('sections=players,activity,streaks');
        $profile = $this->client->getProfile();
        $this->assertNotFalse($profile);

        $queries = $profile->getCollector('db')->getQueries()['default'] ?? [];

        $selects = array_filter($queries, static fn (array $query): bool => 1 === preg_match('/^\s*(SELECT|WITH)\b/i', (string) $query['sql']) && 1 !== preg_match("/= '[^']+'$/", (string) $query['sql']));

        return \count($selects);
    }
}
