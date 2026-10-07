<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UniverseCompletionReward;
use App\Entity\UserCard;
use App\Enum\Coin\UniverseRewardStatusEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Tests\DrawnCardTrait;
use App\Tests\FeatureFlagTrait;
use App\Tests\Support\CoinMockResponses;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GrantCompletedUniversesCommandTest extends KernelTestCase
{
    use DrawnCardTrait;
    use FeatureFlagTrait;

    private EntityManagerInterface $entityManager;

    private CoinMockResponses $coin;

    private Extension $extension;

    private DiscordUser $user;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('cache.app')->clear();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->coin = self::getContainer()->get(CoinMockResponses::class);
        $this->extension = $this->newExtension();
        $this->user = new DiscordUser()->setDiscordId('catchup-' . uniqid())->setUsername('Catchup');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testDryRunListsTheRewardsWithoutWritingNorCallingTheCoin(): void
    {
        $this->give($this->createCard($this->extension));

        $tester = $this->runCommand(['--dry-run' => true]);

        $this->assertStringContainsString('Catchup', $tester->getDisplay());
        $this->assertStringContainsString($this->extension->getName(), $tester->getDisplay());
        $this->assertStringContainsString('total 500 YLC', $tester->getDisplay());
        $this->assertSame([], $this->rewards());
        $this->assertSame([], $this->coin->requests);
    }

    public function testGrantingPaysOnceAndReplayingDoesNothing(): void
    {
        $this->give($this->createCard($this->extension));

        $this->runCommand();
        $this->assertCount(1, $this->rewards());
        $this->assertSame(UniverseRewardStatusEnum::PAID, $this->rewards()[0]->getStatus());
        $this->assertSame(500, $this->rewards()[0]->getAmount());
        $posted = \count($this->coin->requests);

        $replay = $this->runCommand();

        $this->assertStringContainsString('No completed universe without reward', $replay->getDisplay());
        $this->assertCount(1, $this->rewards());
        $this->assertCount($posted, $this->coin->requests);
    }

    public function testAnAlreadyRewardedUniverseIsSkipped(): void
    {
        $this->give($this->createCard($this->extension));
        $this->entityManager->getConnection()->insert('universe_completion_reward', [
            'id' => '0190a000-0000-7000-8000-000000000001',
            'discord_user_id' => $this->user->getDiscordId(),
            'extension_id' => (string) $this->extension->getId(),
            'amount' => 10,
            'status' => UniverseRewardStatusEnum::PAID->value,
            'completed_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $tester = $this->runCommand();

        $this->assertStringContainsString('No completed universe without reward', $tester->getDisplay());
        $this->assertCount(1, $this->rewards());
        $this->assertSame([], $this->coin->requests);
    }

    public function testAUniverseWhoseOnlyMissingCardIsAUniqueIsStillCompleted(): void
    {
        $this->give($this->createCard($this->extension));
        $this->createCard($this->extension, unique: true);

        $this->runCommand();

        $this->assertCount(1, $this->rewards());
    }

    public function testACardNotDrawnByThePlayerBlocksTheCatchUpUntilItIsDrawn(): void
    {
        $this->give($this->createCard($this->extension));
        $received = $this->createCard($this->extension);
        $this->give($received, drawn: false);

        $tester = $this->runCommand();
        $this->assertStringContainsString('No completed universe without reward', $tester->getDisplay());
        $this->assertSame([], $this->rewards());

        $this->recordDraw($this->entityManager, $this->user, $this->entityManager->find(Card::class, $received->getId()));
        $this->runCommand();

        $this->assertCount(1, $this->rewards());
    }

    public function testAnAlreadyPaidRewardIsLeftIntactEvenWithoutDrawnCards(): void
    {
        $this->give($this->createCard($this->extension), drawn: false);
        $reward = new UniverseCompletionReward($this->user, $this->extension, 123, new \DateTimeImmutable('-1 day'));
        $reward->markPaid('old-tx', new \DateTimeImmutable('-1 day'));
        $this->entityManager->persist($reward);
        $this->entityManager->flush();

        $this->runCommand();

        $rewards = $this->rewards();
        $this->assertCount(1, $rewards);
        $this->assertSame(123, $rewards[0]->getAmount());
        $this->assertSame(UniverseRewardStatusEnum::PAID, $rewards[0]->getStatus());
        $this->assertSame([], $this->coin->requests);
    }

    public function testAUniverseMadeOfUniquesOnlyIsIgnored(): void
    {
        $unique = $this->createCard($this->extension, unique: true);
        $this->give($unique);

        $tester = $this->runCommand();

        $this->assertStringContainsString('No completed universe without reward', $tester->getDisplay());
        $this->assertSame([], $this->rewards());
    }

    public function testAMissingCardMeansNoReward(): void
    {
        $this->give($this->createCard($this->extension));
        $this->createCard($this->extension);

        $this->runCommand();

        $this->assertSame([], $this->rewards());
    }

    public function testAZeroAmountLeavesAPaidTraceWithoutCallingTheCoin(): void
    {
        $this->extension->setCompletionRewardCoins(0);
        $this->entityManager->flush();
        $this->give($this->createCard($this->extension));

        $this->runCommand();

        $this->assertCount(1, $this->rewards());
        $this->assertSame(UniverseRewardStatusEnum::PAID, $this->rewards()[0]->getStatus());
        $this->assertSame(0, $this->rewards()[0]->getAmount());
        $this->assertSame([], $this->coin->requests);
    }

    public function testTheCommandRefusesWhileRewardsAreSwitchedOff(): void
    {
        $this->setFeature(FeatureEnum::UNIVERSE_REWARDS, false);
        $this->give($this->createCard($this->extension));
        $tester = new CommandTester(new Application(self::$kernel)->find('app:coin:grant-completed-universes'));

        $this->assertSame(Command::FAILURE, $tester->execute(['--player' => $this->user->getDiscordId()]));
        $this->assertStringContainsString('switched off', $tester->getDisplay());
        $this->assertSame([], $this->rewards());
        $this->assertSame([], $this->coin->requests);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('app:coin:grant-completed-universes'));
        $tester->execute($options + ['--player' => $this->user->getDiscordId()]);
        $tester->assertCommandIsSuccessful();
        $this->entityManager->clear();
        $this->user = $this->entityManager->find(DiscordUser::class, $this->user->getDiscordId());
        $this->extension = $this->entityManager->find(Extension::class, $this->extension->getId());

        return $tester;
    }

    private function newExtension(): Extension
    {
        $extension = new Extension()->setName('Catchup universe ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($extension);
        $this->entityManager->flush();

        return $extension;
    }

    private function createCard(Extension $extension, bool $unique = false): Card
    {
        $card = new Card()
            ->setName('Card ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setUnique($unique)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function give(Card $card, bool $drawn = true): void
    {
        $this->entityManager->persist(new UserCard()->setDiscordUser($this->user)->setCard($card)->setQuantity(1));
        $this->entityManager->flush();
        if ($drawn) {
            $this->recordDraw($this->entityManager, $this->user, $card);
        }
    }

    /**
     * @return list<UniverseCompletionReward>
     */
    private function rewards(): array
    {
        return array_values($this->entityManager->getRepository(UniverseCompletionReward::class)->findBy(['discordUser' => $this->user->getDiscordId()]));
    }
}
