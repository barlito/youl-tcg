<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\Admin\EconomyDashboard;
use App\Dto\Admin\RarityComparisonRow;
use App\Entity\Booster;
use App\Entity\BoosterClaim;
use App\Entity\BoosterCode;
use App\Entity\BoosterCodeRedemption;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\RecycleOperation;
use App\Entity\StreakReward;
use App\Entity\UserBooster;
use App\Enum\Admin\BoosterChannelEnum;
use App\Enum\Admin\EconomyPeriodEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Admin\EconomyStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Real PostgreSQL, isolated dataset: the stat tables are truncated inside the
 * DAMA transaction (rolled back after each test). « Now » is frozen on the
 * 2026 spring DST switch day, noon in Paris.
 */
final class EconomyStatsProviderTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-03-29 10:00:00';

    private EntityManagerInterface $entityManager;

    private EconomyStatsProvider $provider;

    /** @var array<string, DiscordUser> */
    private array $users = [];

    /** @var array<string, Card> */
    private array $cards = [];

    /** @var array<string, Booster> */
    private array $boosters = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE discord_user, extension CASCADE');
        $this->provider = self::getContainer()->get(EconomyStatsProvider::class);

        $this->createDataset();
    }

    public function testKpis(): void
    {
        $kpis = $this->provider->getKpis();

        $this->assertSame(4, $kpis->registeredPlayers);
        $this->assertSame(3, $kpis->activePlayers7Days, 'alice, bob, carol opened in the last 7 Paris days');
        $this->assertSame(4, $kpis->activePlayers30Days, 'dave only claimed, 19 days ago');
        $this->assertSame(2, $kpis->openingsToday, 'the 23:30 UTC opening of yesterday is 00:30 today in Paris');
        $this->assertSame(4, $kpis->openings7Days);
        $this->assertSame(6, $kpis->openingsTotal);
        $this->assertSame(11, $kpis->cardsDrawn, 'quantity already includes the holos');
        $this->assertSame(3, $kpis->holosDrawn);
        $this->assertSame(1, $kpis->uniquesDrawn);
        $this->assertSame(2, $kpis->uniquesTotal, 'the draft, unclaimed 1/1 is not counted');
        $this->assertSame(5, $kpis->unopenedBoosters);
    }

    public function testKpisOnAnEmptyDatabase(): void
    {
        $this->entityManager->getConnection()->executeStatement('TRUNCATE discord_user, extension CASCADE');

        $kpis = $this->provider->getKpis();

        $this->assertSame(0, $kpis->registeredPlayers);
        $this->assertSame(0, $kpis->cardsDrawn);
        $this->assertSame(0, $kpis->unopenedBoosters);

        $dashboard = $this->provider->getDashboard(EconomyPeriodEnum::WEEK);
        $this->assertSame(0, $dashboard->rarityComparison->cardCount);
        $this->assertSame(0.0, $dashboard->rarityComparison->holo->expectedShare);
    }

    public function testDaysAreParisCalendarDaysAcrossTheDstSwitch(): void
    {
        $dashboard = $this->provider->getDashboard(EconomyPeriodEnum::WEEK);

        $this->assertSame(['2026-03-23', '2026-03-24', '2026-03-25', '2026-03-26', '2026-03-27', '2026-03-28', '2026-03-29'], $dashboard->days);
        $this->assertSame(EconomyPeriodEnum::WEEK, $dashboard->period);
        $this->assertCount(30, $this->provider->getDashboard(EconomyPeriodEnum::MONTH)->days);
        $this->assertCount(90, $this->provider->getDashboard(EconomyPeriodEnum::QUARTER)->days);
    }

    public function testOpeningsPerDay(): void
    {
        $dashboard = $this->provider->getDashboard(EconomyPeriodEnum::WEEK);

        $this->assertSame([
            '2026-03-23' => 1,
            '2026-03-24' => 0,
            '2026-03-25' => 1,
            '2026-03-26' => 0,
            '2026-03-27' => 0,
            '2026-03-28' => 0,
            '2026-03-29' => 2,
        ], $dashboard->openingsPerDay);
    }

    public function testActivePlayersPerDayCountsDistinctPlayersOverOpeningsAndClaims(): void
    {
        $dashboard = $this->provider->getDashboard(EconomyPeriodEnum::WEEK);

        $this->assertSame(1, $dashboard->activePlayersPerDay['2026-03-23'], 'alice opened and claimed twice: one player');
        $this->assertSame(0, $dashboard->activePlayersPerDay['2026-03-24'], 'a recycling is not an activity');
        $this->assertSame(1, $dashboard->activePlayersPerDay['2026-03-25']);
        $this->assertSame(2, $dashboard->activePlayersPerDay['2026-03-29']);
    }

    public function testBoostersPerChannel(): void
    {
        $dashboard = $this->provider->getDashboard(EconomyPeriodEnum::WEEK);
        $perChannel = $dashboard->boostersPerChannel;

        $this->assertSame(2, $perChannel[BoosterChannelEnum::DAILY_CLAIM->value]['2026-03-23']);
        $this->assertSame(3, $perChannel[BoosterChannelEnum::CODE->value]['2026-03-26'], 'a redemption counts its quantity');
        $this->assertSame(1, $perChannel[BoosterChannelEnum::STREAK->value]['2026-03-27'], 'only chosen rewards distribute a booster');
        $this->assertSame(2, $perChannel[BoosterChannelEnum::RECYCLE->value]['2026-03-24'], 'a recycling counts its boosterCount');
        $this->assertSame(1, $perChannel[BoosterChannelEnum::RECYCLE->value]['2026-03-29']);

        $this->assertSame([
            BoosterChannelEnum::DAILY_CLAIM->value => 2,
            BoosterChannelEnum::CODE->value => 3,
            BoosterChannelEnum::STREAK->value => 1,
            BoosterChannelEnum::RECYCLE->value => 3,
        ], $dashboard->channelTotals(), 'the 22/03 23:30 Paris claim is out of the period');
    }

    public function testWeeklyRecycles(): void
    {
        $week = $this->provider->getDashboard(EconomyPeriodEnum::WEEK)->weeklyRecycles;

        $this->assertCount(1, $week);
        $this->assertSame('2026-03-23', $week[0]->weekStart);
        $this->assertSame(2, $week[0]->operations);
        $this->assertSame(60, $week[0]->points);
        $this->assertSame(3, $week[0]->boosters);

        $month = $this->provider->getDashboard(EconomyPeriodEnum::MONTH)->weeklyRecycles;

        $this->assertSame(['2026-02-23', '2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23'], array_map(static fn ($stats): string => $stats->weekStart, $month));
        $this->assertSame(1, $month[3]->operations, 'Sunday 22/03 23:30 Paris belongs to the week of the 16th');
        $this->assertSame(2, $month[4]->operations);
    }

    public function testObservedVersusExpectedRarities(): void
    {
        $comparison = $this->provider->getDashboard(EconomyPeriodEnum::WEEK)->rarityComparison;
        $rows = $this->rowsByKey($comparison->rows);

        $this->assertSame(4, $comparison->openingCount);
        $this->assertSame(7, $comparison->cardCount);
        $this->assertSame(['common', 'uncommon', 'rare', 'legendary', 'unique'], array_keys($rows));

        // observed: 4 commons, 2 rares, 1 unique out of 7 cards
        $this->assertSame(4, $rows['common']->observedCount);
        $this->assertSame(57.14, $rows['common']->observedShare);
        $this->assertSame(28.57, $rows['rare']->observedShare);
        $this->assertSame(14.29, $rows['unique']->observedShare);

        // expected: booster A opened 3 times (2 slots), booster B once (1 slot).
        // A slot 2 rolls uncommon (no card → falls back to common) and
        // legendary (no card → falls back to rare), after its 1 % unique chance.
        $this->assertSame(74.68, $rows['common']->expectedShare);
        $this->assertSame(0.0, $rows['uncommon']->expectedShare);
        $this->assertSame(24.89, $rows['rare']->expectedShare);
        $this->assertSame(0.0, $rows['legendary']->expectedShare);
        $this->assertSame(0.43, $rows['unique']->expectedShare);
        $this->assertSame(-17.54, $rows['common']->gap());

        $this->assertSame(3, $comparison->holo->observedCount);
        $this->assertSame(42.86, $comparison->holo->observedShare);
        $this->assertSame(40.0, $comparison->holo->expectedShare);
        $this->assertSame(2.86, $comparison->holo->gap());
    }

    public function testPeriodOutOfRangeFallsBackToDefault(): void
    {
        $this->assertSame(EconomyPeriodEnum::WEEK, EconomyPeriodEnum::fromQuery('7'));
        $this->assertSame(EconomyPeriodEnum::DEFAULT, EconomyPeriodEnum::fromQuery('12'));
        $this->assertSame(EconomyPeriodEnum::DEFAULT, EconomyPeriodEnum::fromQuery(null));
        $this->assertSame(EconomyPeriodEnum::DEFAULT, EconomyPeriodEnum::fromQuery(['x']));
    }

    public function testDashboardIsSerializableForTheCache(): void
    {
        $dashboard = $this->provider->getDashboard(EconomyPeriodEnum::MONTH);

        $this->assertEquals($dashboard, unserialize(serialize($dashboard), ['allowed_classes' => true]));
        $this->assertInstanceOf(EconomyDashboard::class, $dashboard);
    }

    /**
     * @param list<RarityComparisonRow> $rows
     *
     * @return array<string, RarityComparisonRow>
     */
    private function rowsByKey(array $rows): array
    {
        return array_combine(array_map(static fn (RarityComparisonRow $row): string => $row->key, $rows), $rows);
    }

    private function createDataset(): void
    {
        foreach (['alice', 'bob', 'carol', 'dave'] as $name) {
            $user = new DiscordUser()->setDiscordId('eco-' . $name)->setUsername($name);
            $this->entityManager->persist($user);
            $this->users[$name] = $user;
        }

        $extension = new Extension()->setName('Eco')->setDescription('Eco')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($extension);

        // no uncommon and no legendary regular card: exercises the fallback
        $this->createCard('c1', $extension, CardRarityEnum::COMMON);
        $this->createCard('c2', $extension, CardRarityEnum::COMMON);
        $this->createCard('r1', $extension, CardRarityEnum::RARE);
        $this->createCard('u1', $extension, CardRarityEnum::LEGENDARY, unique: true)->setClaimedBy($this->users['alice']);
        $this->createCard('u2', $extension, CardRarityEnum::LEGENDARY, unique: true);
        $this->createCard('u3', $extension, CardRarityEnum::LEGENDARY, unique: true, status: CardStatusEnum::DRAFT);

        $this->boosters['a'] = $this->createBooster($extension, [
            ['rarities' => ['common' => 100], 'holoChance' => 10],
            ['rarities' => ['common' => 50, 'uncommon' => 25, 'legendary' => 25], 'holoChance' => 50, 'uniqueChance' => 100],
        ]);
        $this->boosters['b'] = $this->createBooster($extension, [
            ['rarities' => ['rare' => 100], 'holoChance' => 100],
        ]);

        // in the 7-day period (starts 2026-03-22 23:00 UTC = 23/03 00:00 Paris)
        $this->createOpening('alice', 'a', '2026-03-22 23:30:00', ['c1' => [1, 0], 'u1' => [1, 1]]);
        $this->createOpening('bob', 'a', '2026-03-25 12:00:00', ['c1' => [2, 1]]);
        $this->createOpening('alice', 'a', '2026-03-28 23:30:00', ['c2' => [1, 0], 'r1' => [1, 0]]);
        $this->createOpening('carol', 'b', '2026-03-29 08:00:00', ['r1' => [1, 1]]);
        // 22/03 23:30 Paris: out of the 7 days, in the 30
        $this->createOpening('bob', 'a', '2026-03-22 22:30:00', ['c1' => [2, 0]]);
        // out of the 30 days
        $this->createOpening('carol', 'a', '2026-01-10 12:00:00', ['c1' => [2, 0]]);

        $this->entityManager->persist(new BoosterClaim($this->users['alice'], $this->boosters['a'], $this->utc('2026-03-23 10:00:00')));
        $this->entityManager->persist(new BoosterClaim($this->users['alice'], $this->boosters['a'], $this->utc('2026-03-23 11:00:00')));
        $this->entityManager->persist(new BoosterClaim($this->users['bob'], $this->boosters['a'], $this->utc('2026-03-22 22:30:00')));
        $this->entityManager->persist(new BoosterClaim($this->users['dave'], $this->boosters['a'], $this->utc('2026-03-10 10:00:00')));

        $code = new BoosterCode()->setCode('ECOTEST')->setBooster($this->boosters['a'])->setQuantity(3);
        $this->entityManager->persist($code);
        $this->entityManager->persist(new BoosterCodeRedemption($code, $this->users['bob'], $this->utc('2026-03-26 09:00:00'), 3));

        $chosen = new StreakReward($this->users['alice'], new \DateTimeImmutable('2026-03-15'), 7, $this->utc('2026-03-21 10:00:00'));
        $chosen->choose($this->boosters['a'], $this->utc('2026-03-27 10:00:00'));
        $this->entityManager->persist($chosen);
        $this->entityManager->persist(new StreakReward($this->users['alice'], new \DateTimeImmutable('2026-03-15'), 14, $this->utc('2026-03-28 10:00:00')));

        $this->entityManager->persist(new RecycleOperation($this->users['carol'], $this->boosters['a'], 40, 2, $this->utc('2026-03-24 10:00:00')));
        $this->entityManager->persist(new RecycleOperation($this->users['carol'], $this->boosters['a'], 20, 1, $this->utc('2026-03-28 23:30:00')));
        $this->entityManager->persist(new RecycleOperation($this->users['carol'], $this->boosters['a'], 10, 1, $this->utc('2026-03-22 22:30:00')));

        $this->entityManager->persist(new UserBooster()->setDiscordUser($this->users['alice'])->setBooster($this->boosters['a'])->setQuantity(3));
        $this->entityManager->persist(new UserBooster()->setDiscordUser($this->users['bob'])->setBooster($this->boosters['b'])->setQuantity(2));

        $this->entityManager->flush();
    }

    private function createCard(string $key, Extension $extension, CardRarityEnum $rarity, bool $unique = false, CardStatusEnum $status = CardStatusEnum::PUBLISHED): Card
    {
        $card = new Card()
            ->setName('Eco ' . $key)
            ->setDescription('Eco')
            ->setStatus($status)
            ->setRarity($rarity)
            ->setUnique($unique)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->cards[$key] = $card;

        return $card;
    }

    /**
     * @param list<array{rarities: array<string, int>, holoChance: int, uniqueChance?: int}> $rarityRates
     */
    private function createBooster(Extension $extension, array $rarityRates): Booster
    {
        $booster = new Booster()->setExtension($extension)->setRarityRates($rarityRates);
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        return $booster;
    }

    /**
     * @param array<string, array{int, int}> $cards card key => [quantity, holoQuantity]
     */
    private function createOpening(string $user, string $booster, string $openedAtUtc, array $cards): void
    {
        $opening = new BoosterOpening($this->users[$user], $this->boosters[$booster], 42, $this->utc($openedAtUtc));

        foreach ($cards as $key => [$quantity, $holoQuantity]) {
            $opening->addBoosterOpeningCard(new BoosterOpeningCard($opening, $this->cards[$key], $quantity, $holoQuantity));
        }

        $this->entityManager->persist($opening);
    }

    private function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }
}
