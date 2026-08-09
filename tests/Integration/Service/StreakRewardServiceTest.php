<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\StreakReward;
use App\Entity\UserBooster;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Exception\Booster\BoosterNotClaimableException;
use App\Exception\Booster\StreakRewardUnavailableException;
use App\Repository\BoosterClaimRepository;
use App\Service\Booster\BoosterOpeningService;
use App\Service\Booster\StreakRewardService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StreakRewardServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private StreakRewardService $rewardService;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->rewardService = self::getContainer()->get(StreakRewardService::class);
    }

    public function testSevenConsecutiveDaysGrantOnePendingReward(): void
    {
        $scenario = $this->createScenario();
        $this->createOpenings($scenario['user'], $scenario['booster'], daysAgo: range(0, 6));

        $this->rewardService->grantMilestones($scenario['user']);

        $pending = $this->rewardService->getPendingRewards($scenario['user']);
        $this->assertCount(1, $pending);
        $this->assertSame(7, $pending[0]->getMilestone());
        $this->assertFalse($pending[0]->isChosen());
    }

    public function testGrantingTwiceIsIdempotent(): void
    {
        $scenario = $this->createScenario();
        $this->createOpenings($scenario['user'], $scenario['booster'], daysAgo: range(0, 6));

        $this->rewardService->grantMilestones($scenario['user']);
        $this->rewardService->grantMilestones($scenario['user']);

        $this->assertCount(1, $this->rewardService->getPendingRewards($scenario['user']));
    }

    public function testAFourteenDayStreakGrantsBothMilestones(): void
    {
        $scenario = $this->createScenario();
        $this->createOpenings($scenario['user'], $scenario['booster'], daysAgo: range(0, 13));

        $this->rewardService->grantMilestones($scenario['user']);

        $milestones = array_map(
            static fn (StreakReward $reward): int => $reward->getMilestone(),
            $this->rewardService->getPendingRewards($scenario['user']),
        );
        $this->assertSame([7, 14], $milestones);
    }

    public function testABrokenSeriesGrantsNothing(): void
    {
        $scenario = $this->createScenario();
        // 8 opening days but a hole 3 days ago: the running series is only 3 long
        $this->createOpenings($scenario['user'], $scenario['booster'], daysAgo: [0, 1, 2, 4, 5, 6, 7, 8]);

        $this->rewardService->grantMilestones($scenario['user']);

        $this->assertSame([], $this->rewardService->getPendingRewards($scenario['user']));
    }

    public function testANewSeriesCanEarnTheSameMilestoneAgain(): void
    {
        $scenario = $this->createScenario();
        // milestone 7 already earned by an older, now broken series
        $this->entityManager->persist(new StreakReward(
            $scenario['user'],
            new \DateTimeImmutable('2026-01-01'),
            7,
            new \DateTimeImmutable('2026-01-07 12:00:00'),
        ));
        $this->entityManager->flush();

        $this->createOpenings($scenario['user'], $scenario['booster'], daysAgo: range(0, 6));
        $this->rewardService->grantMilestones($scenario['user']);

        $pending = $this->rewardService->getPendingRewards($scenario['user']);
        $this->assertCount(2, $pending, 'Each series earns its own milestone 7.');
        $this->assertSame([7, 7], array_map(static fn (StreakReward $reward): int => $reward->getMilestone(), $pending));
    }

    public function testOpeningABoosterGrantsTheMilestoneReached(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 1);
        // six previous days: today's opening (the 7th) crosses the milestone
        $this->createOpenings($scenario['user'], $scenario['booster'], daysAgo: range(1, 6));

        self::getContainer()->get(BoosterOpeningService::class)->open($scenario['user'], $scenario['booster']);

        $pending = $this->rewardService->getPendingRewards($scenario['user']);
        $this->assertCount(1, $pending);
        $this->assertSame(7, $pending[0]->getMilestone());
    }

    public function testChooseBoosterCreditsTheInventoryOutsideTheDailyQuota(): void
    {
        $scenario = $this->createScenario();
        $reward = $this->createPendingReward($scenario['user']);

        $chosen = $this->rewardService->chooseBooster($scenario['user'], (string) $reward->getId(), $scenario['booster']);

        $this->assertTrue($chosen->isChosen());
        $this->assertNotNull($chosen->getChosenAt());

        $this->entityManager->clear();

        $userBooster = $this->entityManager->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $scenario['user'], 'booster' => $scenario['booster']])
        ;
        $this->assertSame(1, $userBooster?->getQuantity());

        $this->assertSame(
            0,
            self::getContainer()->get(BoosterClaimRepository::class)->countSince($scenario['user'], new \DateTimeImmutable('-1 day')),
            'A streak reward must not leave a BoosterClaim behind.',
        );
    }

    public function testARewardCannotBeSpentTwice(): void
    {
        $scenario = $this->createScenario();
        $reward = $this->createPendingReward($scenario['user']);

        $this->rewardService->chooseBooster($scenario['user'], (string) $reward->getId(), $scenario['booster']);

        $this->expectException(StreakRewardUnavailableException::class);
        $this->rewardService->chooseBooster($scenario['user'], (string) $reward->getId(), $scenario['booster']);
    }

    public function testChoosingANonClaimableBoosterIsRefused(): void
    {
        $scenario = $this->createScenario(claimable: false);
        $reward = $this->createPendingReward($scenario['user']);

        try {
            $this->rewardService->chooseBooster($scenario['user'], (string) $reward->getId(), $scenario['booster']);
            $this->fail('Expected BoosterNotClaimableException.');
        } catch (BoosterNotClaimableException $exception) {
            $this->assertSame('Ce pack ne peut pas être choisi en récompense.', $exception->getUserMessage());
        }

        $this->entityManager->clear();

        $this->assertSame(
            [],
            $this->entityManager->getRepository(UserBooster::class)->findBy(['discordUser' => $scenario['user']]),
            'The refused choice must not credit anything.',
        );
        $this->assertFalse($this->entityManager->getRepository(StreakReward::class)->find($reward->getId())?->isChosen());
    }

    public function testAnotherPlayersRewardIsOutOfReach(): void
    {
        $scenario = $this->createScenario();
        $reward = $this->createPendingReward($scenario['user']);

        $intruder = new DiscordUser()->setDiscordId('streak-intruder-' . uniqid())->setUsername('Intruder');
        $this->entityManager->persist($intruder);
        $this->entityManager->flush();

        $this->expectException(StreakRewardUnavailableException::class);
        $this->rewardService->chooseBooster($intruder, (string) $reward->getId(), $scenario['booster']);
    }

    /**
     * @return array{user: DiscordUser, booster: Booster}
     */
    private function createScenario(bool $claimable = true, int $boosterQuantity = 0): array
    {
        $extension = new \App\Entity\Extension()
            ->setName('Streak test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        $card = new Card()
            ->setName('Streak test card')
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        $booster = new Booster()
            ->setExtension($extension)
            ->setClaimable($claimable)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        $user = new DiscordUser()->setDiscordId('streak-test-' . uniqid())->setUsername('Streak tester');
        $this->entityManager->persist($user);

        if ($boosterQuantity > 0) {
            $this->entityManager->persist(
                new UserBooster()->setDiscordUser($user)->setBooster($booster)->setQuantity($boosterQuantity),
            );
        }

        $this->entityManager->flush();

        return ['user' => $user, 'booster' => $booster];
    }

    /**
     * @param list<int> $daysAgo
     */
    private function createOpenings(DiscordUser $user, Booster $booster, array $daysAgo): void
    {
        $now = self::getContainer()->get(ClockInterface::class)->now();

        foreach ($daysAgo as $days) {
            $this->entityManager->persist(
                new BoosterOpening($user, $booster, seed: 42, openedAt: $now->modify(\sprintf('-%d days', $days))),
            );
        }

        $this->entityManager->flush();
    }

    private function createPendingReward(DiscordUser $user): StreakReward
    {
        $reward = new StreakReward(
            $user,
            new \DateTimeImmutable('7 days ago'),
            7,
            new \DateTimeImmutable(),
        );
        $this->entityManager->persist($reward);
        $this->entityManager->flush();

        return $reward;
    }
}
