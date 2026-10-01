<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\TradeLineRequest;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\Notification;
use App\Entity\UniverseCompletionReward;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Coin\UniverseRewardStatusEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Repository\CoinSettingsRepository;
use App\Service\Booster\BoosterOpeningService;
use App\Service\Coin\UniverseCompletionChecker;
use App\Service\Coin\UniverseRewardService;
use App\Service\Trade\TradeOfferService;
use App\Tests\FeatureFlagTrait;
use App\Tests\Support\CoinMockResponses;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UniverseCompletionRewardTest extends KernelTestCase
{
    use FeatureFlagTrait;

    private EntityManagerInterface $entityManager;

    private UniverseCompletionChecker $checker;

    private CoinMockResponses $coin;

    private Extension $extension;

    private DiscordUser $user;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('cache.app')->clear();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->checker = self::getContainer()->get(UniverseCompletionChecker::class);
        $this->coin = self::getContainer()->get(CoinMockResponses::class);

        $this->extension = new Extension()->setName('Reward universe ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($this->extension);
        $this->user = $this->createUser('player');
    }

    public function testCompletingAUniverseIgnoringUniquesPaysTheDefaultRewardOnce(): void
    {
        $common = $this->createCard('Common');
        $this->createCard('Unique 1/1', unique: true);
        $this->give($this->user, $common);

        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $reward = $this->onlyReward();
        $this->assertSame(UniverseRewardStatusEnum::PAID, $reward->getStatus());
        $this->assertSame(500, $reward->getAmount());
        $this->assertSame(CoinMockResponses::TRANSACTION_ID, $reward->getCoinTransactionId());

        $post = $this->postedTransactions();
        $this->assertCount(1, $post);
        $this->assertSame(['amount' => '50000000000', 'walletFrom' => '/api/wallets/' . CoinMockResponses::BANK_WALLET_ID, 'walletTo' => '/api/wallets/' . CoinMockResponses::USER_WALLET_ID, 'type' => 'reward', 'externalIdentifier' => 'ytcg:universe-reward:' . $reward->getId(), 'description' => 'Univers ' . $this->extension->getName() . ' complété'], $post[0]['body']);
        $this->assertArrayNotHasKey('x-player-token', $post[0]['headers']);

        $notifications = $this->notifications();
        $this->assertCount(1, $notifications);
        $this->assertSame(['universe' => $this->extension->getName(), 'slug' => $this->extension->getSlug(), 'amount' => 500], $notifications[0]->getPayload());
        $this->assertNull($notifications[0]->getReadAt());
    }

    public function testAMissingCardMeansNoReward(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->createCard('Missing');

        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $this->assertSame([], $this->rewards());
    }

    public function testAnUnpublishedCardIsIgnored(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->createCard('Draft', status: CardStatusEnum::DRAFT);

        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $this->assertCount(1, $this->rewards());
    }

    public function testAnUnpublishedExtensionIsIgnored(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->extension->setStatus(ExtensionStatusEnum::DRAFT);
        $this->entityManager->flush();

        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $this->assertSame([], $this->rewards());
    }

    public function testAUniverseMadeOfUniquesOnlyCannotBeCompleted(): void
    {
        $this->createCard('Only unique', unique: true);

        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $this->assertSame([], $this->rewards());
    }

    public function testTheRewardIsNeverGrantedTwiceEvenAfterNewCardsOrASaleAndRecompletion(): void
    {
        $first = $this->createCard('First');
        $this->give($this->user, $first);
        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $second = $this->createCard('Second');
        $userCard = $this->give($this->user, $second);
        $this->checker->checkAfterCredit($this->user, [$this->extension]);
        $userCard->setQuantity(0);
        $this->entityManager->flush();
        $userCard->setQuantity(1);
        $this->entityManager->flush();
        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $this->assertCount(1, $this->rewards());
        $this->assertCount(1, $this->postedTransactions());
        $this->assertCount(1, $this->notifications());
    }

    public function testTheExtensionAmountOverridesTheDefaultAndTheSettingsDefaultApplies(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $settings = self::getContainer()->get(CoinSettingsRepository::class)->get();
        $settings->setDefaultUniverseRewardCoins(300);
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        $this->checker->checkAfterCredit($this->user, [$this->extension]);
        $this->assertSame(300, $this->onlyReward()->getAmount());

        $other = $this->newExtension();
        $other->setCompletionRewardCoins(50);
        $this->give($this->user, $this->createCard('Other owned', $other));
        $this->checker->checkAfterCredit($this->user, [$other]);

        $this->assertSame([300, 50], array_map(static fn (UniverseCompletionReward $reward): int => $reward->getAmount(), $this->rewards()));
    }

    public function testAZeroAmountLeavesATraceWithoutPayingNorNotifying(): void
    {
        $this->extension->setCompletionRewardCoins(0);
        $this->give($this->user, $this->createCard('Owned'));

        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $this->assertSame(UniverseRewardStatusEnum::PAID, $this->onlyReward()->getStatus());
        $this->assertNull($this->onlyReward()->getCoinTransactionId());
        $this->assertSame([], $this->postedTransactions());
        $this->assertSame([], $this->notifications());
    }

    public function testAnUncertainPaymentStaysPendingThenIsPaidByTheRetry(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));

        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $this->assertSame(UniverseRewardStatusEnum::PENDING, $this->onlyReward()->getStatus());
        $this->assertSame([], $this->notifications());

        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx-retry'], 201));
        self::getContainer()->get(UniverseRewardService::class)->payPending();
        self::getContainer()->get(UniverseRewardService::class)->payPending();

        $this->assertSame(UniverseRewardStatusEnum::PAID, $this->onlyReward()->getStatus());
        $this->assertSame('tx-retry', $this->onlyReward()->getCoinTransactionId());
        $this->assertCount(1, $this->notifications());
    }

    public function testThePlayerScopedRetryWaitsAMinute(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['http_code' => 502]));
        $this->checker->checkAfterCredit($this->user, [$this->extension]);
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx'], 201));

        self::getContainer()->get(UniverseRewardService::class)->payPending($this->user);
        $this->assertSame(UniverseRewardStatusEnum::PENDING, $this->onlyReward()->getStatus());

        $this->entityManager->getConnection()->executeStatement("UPDATE universe_completion_reward SET completed_at = (NOW() AT TIME ZONE 'UTC') - INTERVAL '5 minutes' WHERE discord_user_id = ?", [$this->user->getDiscordId()]);
        self::getContainer()->get(UniverseRewardService::class)->payPending($this->user);

        $this->assertSame(UniverseRewardStatusEnum::PAID, $this->onlyReward()->getStatus());
    }

    public function testARefusalFailsTheRewardAndOnlyRetryFailedPaysItAgain(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('{}', ['http_code' => 422]));

        $this->checker->checkAfterCredit($this->user, [$this->extension]);
        $this->assertSame(UniverseRewardStatusEnum::FAILED, $this->onlyReward()->getStatus());

        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx-later'], 201));
        $service = self::getContainer()->get(UniverseRewardService::class);
        $service->payPending();
        $this->assertSame(UniverseRewardStatusEnum::FAILED, $this->onlyReward()->getStatus());

        $service->payPending(includeFailed: true);
        $this->assertSame(UniverseRewardStatusEnum::PAID, $this->onlyReward()->getStatus());
        $this->assertCount(1, $this->notifications());
    }

    public function testOpeningTheLastMissingCardTriggersTheReward(): void
    {
        $card = $this->createCard('Only card');
        $booster = new Booster()->setExtension($this->extension)->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]]);
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);
        $this->entityManager->persist(new UserBooster()->setDiscordUser($this->user)->setBooster($booster)->setQuantity(1));
        $this->entityManager->flush();

        self::getContainer()->get(BoosterOpeningService::class)->open($this->user, $booster);

        $this->assertNotNull($card->getId());
        $this->assertSame(UniverseRewardStatusEnum::PAID, $this->onlyReward()->getStatus());
    }

    public function testAnAcceptedTradeRewardsBothPlayersWhoCompleteTheUniverse(): void
    {
        $cardA = $this->createCard('A');
        $cardB = $this->createCard('B');
        $bob = $this->createUser('bob');
        $this->give($this->user, $cardA, 2);
        $this->give($bob, $cardB, 2);
        $service = self::getContainer()->get(TradeOfferService::class);

        $offer = $service->create($this->user, $bob, [new TradeLineRequest($cardA, 1)], [new TradeLineRequest($cardB, 1)]);
        $this->assertSame([], $this->rewards());

        $service->accept($offer, $bob);

        $rewarded = array_map(static fn (UniverseCompletionReward $reward): string => $reward->getDiscordUser()->getDiscordId(), $this->rewards());
        sort($rewarded);
        $expected = [$this->user->getDiscordId(), $bob->getDiscordId()];
        sort($expected);
        $this->assertSame($expected, $rewarded);
    }

    public function testSwitchedOffRewardsGrantPayAndNotifyNothing(): void
    {
        $this->setFeature(FeatureEnum::UNIVERSE_REWARDS, false);
        $this->give($this->user, $this->createCard('Owned'));

        $this->checker->checkAfterCredit($this->user, [$this->extension]);
        $this->assertNull($this->checker->rewardCompleted($this->user, $this->extension));

        $this->assertSame([], $this->rewards());
        $this->assertSame([], $this->postedTransactions());
        $this->assertSame([], $this->notifications());
    }

    public function testSwitchedOffRewardsIgnoreTheLastCardOfAnOpening(): void
    {
        $this->setFeature(FeatureEnum::UNIVERSE_REWARDS, false);
        $this->createCard('Only card');
        $booster = new Booster()->setExtension($this->extension)->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]]);
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);
        $this->entityManager->persist(new UserBooster()->setDiscordUser($this->user)->setBooster($booster)->setQuantity(1));
        $this->entityManager->flush();

        self::getContainer()->get(BoosterOpeningService::class)->open($this->user, $booster);

        $this->assertSame([], $this->rewards());
    }

    public function testSwitchedOffRewardsLeavePendingOnesUnpaid(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['http_code' => 502]));
        $this->checker->checkAfterCredit($this->user, [$this->extension]);
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx'], 201));
        $posted = \count($this->postedTransactions());

        $this->setFeature(FeatureEnum::UNIVERSE_REWARDS, false);
        $service = self::getContainer()->get(UniverseRewardService::class);
        $service->payPending(includeFailed: true);
        $service->pay($this->onlyReward());

        $this->assertSame(UniverseRewardStatusEnum::PENDING, $this->onlyReward()->getStatus());
        $this->assertCount($posted, $this->postedTransactions());
    }

    public function testACancelledRewardIsNeverPaidAgainEvenWithRetryFailed(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('{}', ['http_code' => 422]));
        $this->checker->checkAfterCredit($this->user, [$this->extension]);
        $this->coin->override('POST', '/api/transactions', static fn (): MockResponse => CoinMockResponses::json(['id' => 'tx-later'], 201));
        $service = self::getContainer()->get(UniverseRewardService::class);

        $this->assertTrue($service->cancel($this->onlyReward()));
        $this->assertFalse($service->cancel($this->onlyReward()), 'cancelling twice changes nothing');
        $posted = \count($this->postedTransactions());
        $service->payPending(includeFailed: true);
        $service->pay($this->onlyReward());

        $this->assertSame(UniverseRewardStatusEnum::CANCELLED, $this->onlyReward()->getStatus());
        $this->assertCount($posted, $this->postedTransactions());
        $this->assertSame([], $this->notifications());
    }

    public function testAPaidRewardCanBeCancelledAndIsNeverGrantedAgain(): void
    {
        $this->give($this->user, $this->createCard('Owned'));
        $this->checker->checkAfterCredit($this->user, [$this->extension]);
        $service = self::getContainer()->get(UniverseRewardService::class);

        $this->assertTrue($service->cancel($this->onlyReward()));
        $this->checker->checkAfterCredit($this->user, [$this->extension]);

        $this->assertSame(UniverseRewardStatusEnum::CANCELLED, $this->onlyReward()->getStatus());
        $this->assertCount(1, $this->postedTransactions());
    }

    private function newExtension(): Extension
    {
        $extension = new Extension()->setName('Other reward universe ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($extension);

        return $extension;
    }

    private function createUser(string $prefix): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId($prefix . '-' . uniqid())->setUsername(ucfirst($prefix));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createCard(string $name, ?Extension $extension = null, bool $unique = false, CardStatusEnum $status = CardStatusEnum::PUBLISHED): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension ?? $this->extension)
            ->setStatus($status)
            ->setRarity(CardRarityEnum::COMMON)
            ->setUnique($unique)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function give(DiscordUser $user, Card $card, int $quantity = 1): UserCard
    {
        $userCard = new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($quantity);
        $this->entityManager->persist($userCard);
        $this->entityManager->flush();

        return $userCard;
    }

    /**
     * @return list<UniverseCompletionReward>
     */
    private function rewards(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(UniverseCompletionReward::class)->findBy([], ['amount' => 'DESC']),
            fn (UniverseCompletionReward $reward): bool => str_starts_with($reward->getDiscordUser()->getDiscordId(), 'player-') || str_starts_with($reward->getDiscordUser()->getDiscordId(), 'bob-'),
        ));
    }

    private function onlyReward(): UniverseCompletionReward
    {
        $rewards = array_values(array_filter($this->rewards(), fn (UniverseCompletionReward $reward): bool => $reward->getDiscordUser()->getDiscordId() === $this->user->getDiscordId()));
        $this->assertCount(1, $rewards);

        return $rewards[0];
    }

    /**
     * @return list<Notification>
     */
    private function notifications(): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy(['recipient' => $this->user->getDiscordId(), 'type' => NotificationTypeEnum::UNIVERSE_COMPLETED]);
    }

    /**
     * @return list<array{body: array<string, mixed>, headers: array<string, mixed>}>
     */
    private function postedTransactions(): array
    {
        $posts = [];
        foreach ($this->coin->requests as $request) {
            if ('POST' === $request['method']) {
                $posts[] = ['body' => json_decode((string) $request['options']['body'], true, flags: \JSON_THROW_ON_ERROR), 'headers' => $request['options']['normalized_headers']];
            }
        }

        return $posts;
    }
}
