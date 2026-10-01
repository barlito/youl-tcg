<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\UniverseCompletionReward;
use App\Enum\Coin\UniverseRewardStatusEnum;
use App\Enum\FeatureEnum;
use App\Repository\DiscordUserRepository;
use App\Repository\ExtensionRepository;
use App\Tests\FeatureFlagTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PendingRewardRetryTest extends WebTestCase
{
    use FeatureFlagTrait;
    use JwtAuthTrait;

    private const string JUJU = '195659530363731968';

    public function testAPageViewRetriesThePlayersOldPendingReward(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::JUJU);
        $reward = $this->pendingReward($this->completedAt('-5 minutes'));

        $client->request('GET', '/');

        static::getContainer()->get(EntityManagerInterface::class)->refresh($reward);
        $this->assertSame(UniverseRewardStatusEnum::PAID, $reward->getStatus());
    }

    public function testAFreshPendingRewardIsLeftAlone(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::JUJU);
        $reward = $this->pendingReward($this->completedAt('-5 seconds'));

        $client->request('GET', '/');

        static::getContainer()->get(EntityManagerInterface::class)->refresh($reward);
        $this->assertSame(UniverseRewardStatusEnum::PENDING, $reward->getStatus());
    }

    public function testTheCommandPaysPendingAndOnlyRetriesFailedOnDemand(): void
    {
        static::bootKernel();
        $pending = $this->pendingReward($this->completedAt('-1 second'));
        $failed = $this->pendingReward($this->completedAt('-1 second'), 'other');
        $failed->markFailed();
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $tester = new CommandTester(new Application(static::$kernel)->find('app:coin:pay-pending-rewards'));

        $tester->execute([]);
        $this->assertStringContainsString('1 reward(s) to pay, 1 paid.', $tester->getDisplay());
        $this->assertSame(UniverseRewardStatusEnum::PAID, $pending->getStatus());
        $this->assertSame(UniverseRewardStatusEnum::FAILED, $failed->getStatus());

        $tester->execute(['--retry-failed' => true]);
        $this->assertSame(UniverseRewardStatusEnum::PAID, $failed->getStatus());
    }

    public function testAPageViewPaysNothingWhileRewardsAreSwitchedOff(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::JUJU);
        $this->setFeature(FeatureEnum::UNIVERSE_REWARDS, false);
        $reward = $this->pendingReward($this->completedAt('-5 minutes'));

        $client->request('GET', '/');

        static::getContainer()->get(EntityManagerInterface::class)->refresh($reward);
        $this->assertSame(UniverseRewardStatusEnum::PENDING, $reward->getStatus());
    }

    public function testTheCommandRefusesWhileRewardsAreSwitchedOff(): void
    {
        static::bootKernel();
        $this->setFeature(FeatureEnum::UNIVERSE_REWARDS, false);
        $reward = $this->pendingReward($this->completedAt('-1 second'));
        $tester = new CommandTester(new Application(static::$kernel)->find('app:coin:pay-pending-rewards'));

        $this->assertSame(Command::FAILURE, $tester->execute(['--retry-failed' => true]));
        $this->assertStringContainsString('switched off', $tester->getDisplay());
        $this->assertSame(UniverseRewardStatusEnum::PENDING, $reward->getStatus());
    }

    private function completedAt(string $modifier): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->modify($modifier);
    }

    private function pendingReward(\DateTimeImmutable $completedAt, string $extensionOffset = 'first'): UniverseCompletionReward
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $extensions = static::getContainer()->get(ExtensionRepository::class)->findBy([], ['name' => 'ASC']);
        $extension = 'first' === $extensionOffset ? $extensions[0] : $extensions[1];
        $reward = new UniverseCompletionReward(static::getContainer()->get(DiscordUserRepository::class)->find(self::JUJU), $extension, 100, $completedAt);
        $entityManager->persist($reward);
        $entityManager->flush();

        return $reward;
    }
}
