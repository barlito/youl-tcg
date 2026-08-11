<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\DiscordUser;
use App\Entity\StreakReward;
use App\Entity\UserBooster;
use App\Repository\BoosterRepository;
use App\Twig\Components\BoosterHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class StreakHubComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    // Juju has no opening nor UserBooster fixture: clean streak state.
    private const string USER_WITHOUT_INVENTORY = '195659530363731968';

    public function testTheEmptyStateInvitesToStartASeries(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $rendered = (string) $this->createLiveComponent(BoosterHub::class, client: $client)->render();

        $this->assertStringContainsString('ouvre un pack', $this->streakTile($rendered));
        $this->assertStringNotContainsString('data-testid="streak-reward-banner"', $rendered);
    }

    public function testTheFlameShowsTheRunningStreak(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $this->createOpenings($user, daysAgo: [0, 1, 2]);

        $rendered = (string) $this->createLiveComponent(BoosterHub::class, client: $client)->render();

        $tile = $this->streakTile($rendered);
        $this->assertStringContainsString('3j', $tile);
        $this->assertStringContainsString('palier à 7j', $tile);
        $this->assertStringNotContainsString('aujourd\'hui', $tile);
    }

    public function testASeriesNotFedTodayAsksForAnOpening(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $this->createOpenings($user, daysAgo: [1, 2]);

        $rendered = (string) $this->createLiveComponent(BoosterHub::class, client: $client)->render();

        $tile = $this->streakTile($rendered);
        $this->assertStringContainsString('2j', $tile);
        $this->assertStringContainsString('ouvre aujourd\'hui !', $tile);
    }

    public function testAPendingRewardShowsTheBannerWithClaimableChoicesOnly(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $this->createPendingReward($user);
        $this->createEventBooster('Pack Event Streak');

        $rendered = (string) $this->createLiveComponent(BoosterHub::class, client: $client)->render();

        $this->assertStringContainsString('data-testid="streak-reward-banner"', $rendered);
        $this->assertStringContainsString('palier 7 jours', $rendered);
        $this->assertStringContainsString('data-testid="streak-reward-choice"', $rendered);
        // the event/code pack is not claimable: never offered as a streak bonus
        $this->assertStringNotContainsString('Pack Event Streak', $rendered);
    }

    public function testChoosingABoosterCreditsTheInventoryAndClosesTheReward(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $reward = $this->createPendingReward($user);
        $booster = $this->firstClaimableBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->set('streakRewardBoosterId', (string) $booster->getId());
        $component->call('chooseStreakReward', ['rewardId' => (string) $reward->getId()]);

        $this->assertNull($component->component()->streakError);
        $this->assertStringContainsString('Palier 7 jours', (string) $component->component()->streakSuccess);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $userBooster = $entityManager->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $user, 'booster' => $booster])
        ;
        $this->assertSame(1, $userBooster?->getQuantity());
        $this->assertTrue($entityManager->getRepository(StreakReward::class)->find($reward->getId())?->isChosen());

        // the banner is gone, the success bandeau remains
        $rendered = (string) $component->render();
        $this->assertStringNotContainsString('data-testid="streak-reward-choice"', $rendered);
        $this->assertStringContainsString('data-testid="streak-success"', $rendered);
    }

    public function testARewardCannotBeSpentTwice(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $reward = $this->createPendingReward($user);
        $booster = $this->firstClaimableBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->set('streakRewardBoosterId', (string) $booster->getId());
        $component->call('chooseStreakReward', ['rewardId' => (string) $reward->getId()]);

        // the selector is cleared after a success: pick again to prove the
        // refusal comes from the reward being spent, not from an empty choice
        $component->set('streakRewardBoosterId', (string) $booster->getId());
        $component->call('chooseStreakReward', ['rewardId' => (string) $reward->getId()]);

        $this->assertSame('Cette récompense n\'est plus disponible.', $component->component()->streakError);

        $userBooster = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $user, 'booster' => $booster])
        ;
        $this->assertSame(1, $userBooster?->getQuantity(), 'The second call must not credit again.');
    }

    public function testAForgedChoiceOfANonClaimableBoosterIsRefused(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $reward = $this->createPendingReward($user);
        $eventBooster = $this->createEventBooster('Pack Event Forgé');

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->set('streakRewardBoosterId', (string) $eventBooster->getId());
        $component->call('chooseStreakReward', ['rewardId' => (string) $reward->getId()]);

        $this->assertSame('Ce pack ne peut pas être choisi en récompense.', $component->component()->streakError);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertSame([], $entityManager->getRepository(UserBooster::class)->findBy(['discordUser' => $user]));
        $this->assertFalse($entityManager->getRepository(StreakReward::class)->find($reward->getId())?->isChosen());
    }

    public function testMalformedIdsAreReportedWithoutCrashing(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $this->createPendingReward($user);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);

        $component->set('streakRewardBoosterId', (string) $this->firstClaimableBooster()->getId());
        $component->call('chooseStreakReward', ['rewardId' => 'not-a-uuid']);
        $this->assertSame('Cette récompense n\'est plus disponible.', $component->component()->streakError);

        // nothing picked in the selector, or a forged id: same clean refusal
        $component->set('streakRewardBoosterId', 'not-a-uuid');
        $component->call('chooseStreakReward', ['rewardId' => 'not-a-uuid']);
        $this->assertSame('Choisis un pack avant de valider.', $component->component()->streakError);
    }

    public function testQueuedRewardsAreSpentOneAtATimeOnDistinctPacks(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $first = $this->createPendingReward($user, milestone: 7);
        $this->createPendingReward($user, milestone: 14);
        [$packA, $packB] = $this->twoClaimableBoosters();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        // only the oldest milestone is offered at a time, and the title counts the queue
        $rendered = (string) $component->render();
        $this->assertStringContainsString('palier 7 jours', $rendered);
        $this->assertStringContainsString('· 1 / 2', $rendered);

        $component->set('streakRewardBoosterId', (string) $packA->getId());
        $component->call('chooseStreakReward', ['rewardId' => (string) $first->getId()]);
        $this->assertNull($component->component()->streakError);
        $this->assertStringContainsString('reste 1 récompense', (string) $component->component()->streakSuccess);

        // the next one takes its place, and may go to a DIFFERENT pack
        $rendered = (string) $component->render();
        $this->assertStringContainsString('palier 14 jours', $rendered);
        $this->assertStringNotContainsString('palier 7 jours', $rendered);

        $second = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(StreakReward::class)
            ->findOneBy(['discordUser' => $user, 'milestone' => 14])
        ;
        $component->set('streakRewardBoosterId', (string) $packB->getId());
        $component->call('chooseStreakReward', ['rewardId' => (string) $second?->getId()]);
        $this->assertNull($component->component()->streakError);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([$packA, $packB] as $pack) {
            $userBooster = $entityManager->getRepository(UserBooster::class)
                ->findOneBy(['discordUser' => $user, 'booster' => $pack])
            ;
            $this->assertSame(1, $userBooster?->getQuantity(), 'Chaque récompense crédite son propre pack.');
        }

        $this->assertStringNotContainsString('data-testid="streak-reward-choice"', (string) $component->render());
    }

    /**
     * @return array{Booster, Booster}
     */
    private function twoClaimableBoosters(): array
    {
        $boosters = static::getContainer()->get(BoosterRepository::class)->findPublished();
        $claimable = array_values(array_filter($boosters, static fn (Booster $booster): bool => $booster->isClaimable()));
        $this->assertGreaterThanOrEqual(2, \count($claimable), 'Two claimable packs are needed.');

        return [$claimable[0], $claimable[1]];
    }

    /**
     * Text of the streak tile, whitespace normalized: the assertions target
     * what the player reads, not the markup around it.
     */
    private function streakTile(string $rendered): string
    {
        return new Crawler($rendered)->filter('[data-testid="streak-flame"]')->text(normalizeWhitespace: true);
    }

    /**
     * @param list<int> $daysAgo
     */
    private function createOpenings(DiscordUser $user, array $daysAgo): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $booster = $this->firstClaimableBooster();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($daysAgo as $days) {
            $entityManager->persist(
                new BoosterOpening($user, $booster, seed: 42, openedAt: $now->modify(\sprintf('-%d days', $days))),
            );
        }

        $entityManager->flush();
    }

    private function createPendingReward(DiscordUser $user, int $milestone = 7): StreakReward
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $reward = new StreakReward(
            $user,
            new \DateTimeImmutable($milestone . ' days ago'),
            $milestone,
            new \DateTimeImmutable(),
        );
        $entityManager->persist($reward);
        $entityManager->flush();

        return $reward;
    }

    private function createEventBooster(string $name): Booster
    {
        $booster = new Booster()
            ->setExtension($this->firstClaimableBooster()->getExtension())
            ->setName($name)
            ->setClaimable(false)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($booster);
        $entityManager->flush();

        return $booster;
    }

    private function firstClaimableBooster(): Booster
    {
        foreach (static::getContainer()->get(BoosterRepository::class)->findPublished() as $booster) {
            if ($booster->isClaimable()) {
                return $booster;
            }
        }

        $this->fail('No claimable booster fixture found, load the alice fixtures first.');
    }
}
