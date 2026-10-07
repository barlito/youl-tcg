<?php

declare(strict_types=1);

namespace App\Service\Home;

use App\Entity\BoosterOpeningCard;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Enum\FeatureEnum;
use App\Repository\BoosterOpeningCardRepository;
use App\Repository\BoosterOpeningRepository;
use App\Repository\CardRepository;
use App\Repository\FusionOperationRepository;
use App\Repository\MarketListingRepository;
use App\Repository\TradeOfferRepository;
use App\Repository\UniverseCompletionRewardRepository;
use App\Repository\UserBoosterRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterClaimQuotaInterface;
use App\Service\Booster\BoosterClaimService;
use App\Service\Booster\OpeningStreakService;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\UniverseCompletionChecker;
use App\Service\Feature\FeatureFlags;
use App\Service\Fusion\FusionService;
use App\Service\Leaderboard\LeaderboardService;
use App\Service\Recycle\RecycleService;
use App\Service\Trade\EngagedCopies;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Data of the homepage blocks: the player's « QG du jour », the universes he is
 * closest to finishing, and the live community feed. Nothing here reveals a card
 * the player does not own: pulls only expose rarity + universe, market listings
 * are the assumed exception of the masking rule.
 */
final readonly class HomeDashboardBuilder
{
    public const int UNIVERSES_TO_FINISH = 3;

    public const int NOTABLE_PULLS = 6;

    public const int LATEST_LISTINGS = 4;

    private const string COMMUNITY_CACHE_KEY = 'home_community';

    private const int COMMUNITY_CACHE_TTL = 300;

    public function __construct(
        private BoosterClaimService $claimService,
        private OpeningStreakService $streakService,
        private LeaderboardService $leaderboardService,
        private RecycleService $recycleService,
        private FeatureFlags $featureFlags,
        private EngagedCopies $engagedCopies,
        private UniverseCompletionChecker $completionChecker,
        private UserCardRepository $userCardRepository,
        private UserBoosterRepository $userBoosterRepository,
        private TradeOfferRepository $tradeOfferRepository,
        private MarketListingRepository $marketListingRepository,
        private CardRepository $cardRepository,
        private BoosterOpeningRepository $boosterOpeningRepository,
        private BoosterOpeningCardRepository $boosterOpeningCardRepository,
        private FusionOperationRepository $fusionOperationRepository,
        private UniverseCompletionRewardRepository $rewardRepository,
        private CacheInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     claims: array{remaining: int, limit: int, secondsUntilReset: int},
     *     unopenedBoosters: int,
     *     streak: array{length: int, openedToday: bool, atRisk: bool, nextMilestone: int},
     *     rank: int,
     *     completionPct: int,
     *     pendingTrades: int|null,
     *     fusions: array{cards: int, possible: int}|null,
     *     recycledToday: bool|null,
     *     wishesOnSale: int|null,
     * }
     */
    public function today(DiscordUser $user): array
    {
        $streak = $this->streakService->getStreak($user);
        $entry = $this->leaderboardService->getEntryFor($user);

        return [
            'claims' => [
                'remaining' => $this->claimService->getRemainingClaims($user),
                'limit' => BoosterClaimQuotaInterface::DAILY_LIMIT,
                'secondsUntilReset' => $this->claimService->getSecondsUntilReset(),
            ],
            'unopenedBoosters' => $this->userBoosterRepository->sumQuantityFor($user),
            'streak' => [
                'length' => $streak->length,
                'openedToday' => $streak->openedToday,
                'atRisk' => $streak->isAtRisk(),
                'nextMilestone' => $streak->nextMilestone(),
            ],
            'rank' => $entry->rank,
            'completionPct' => $entry->completionPct,
            'pendingTrades' => $this->featureFlags->isEnabled(FeatureEnum::TRADES) ? $this->tradeOfferRepository->countPendingForReceiver($user) : null,
            'fusions' => $this->featureFlags->isEnabled(FeatureEnum::FUSION) ? $this->fusions($user) : null,
            'recycledToday' => $this->featureFlags->isEnabled(FeatureEnum::RECYCLING) ? $this->recycleService->hasRecycledToday($user) : null,
            'wishesOnSale' => $this->featureFlags->isEnabled(FeatureEnum::WISHLIST) ? $this->marketListingRepository->countActive(null, $user, null, null, null, $user) : null,
        ];
    }

    /**
     * The started universes closest to 100 % (same completion as /univers),
     * plus the self-drawn reward progress while rewards are on.
     *
     * @param list<array{extension: Extension, cardCount: int, hasClaimableBooster: bool}> $extensions  published universes with their published card count
     * @param array<string, string>                                                        $coverImages extension id => fallback artwork
     *
     * @return list<array{extension: Extension, owned: int, total: int, percentage: int, coverImage: string|null, reward: array{drawn: int, total: int, amount: string}|null}>
     */
    public function universesToFinish(DiscordUser $user, array $extensions, array $coverImages): array
    {
        $owned = $this->userCardRepository->countOwnedGroupedByExtension($user);

        $started = [];
        foreach ($extensions as $item) {
            $extensionId = (string) $item['extension']->getId();
            $count = $owned[$extensionId] ?? 0;
            if (0 === $count || $count >= $item['cardCount']) {
                continue;
            }
            $started[] = [
                'extension' => $item['extension'],
                'owned' => $count,
                'total' => $item['cardCount'],
                'percentage' => (int) floor($count / $item['cardCount'] * 100),
                'coverImage' => $coverImages[$extensionId] ?? null,
            ];
        }

        usort($started, static fn (array $a, array $b): int => [$b['percentage'], $b['owned']] <=> [$a['percentage'], $a['owned']]);
        $started = \array_slice($started, 0, self::UNIVERSES_TO_FINISH);

        $rewards = $this->rewardProgress($user, array_column($started, 'extension'));

        return array_map(static fn (array $universe): array => [
            ...$universe,
            'reward' => $rewards[(string) $universe['extension']->getId()] ?? null,
        ], $started);
    }

    /**
     * Shared by every player: cached a few minutes.
     *
     * @return array{
     *     pulls: list<array{player: string, discordId: string, rarity: string, unique: bool, holo: bool, universe: string, slug: string, openedAt: \DateTimeImmutable}>,
     *     uniques: array{total: int, found: int},
     *     holoCopies: int,
     *     tradesAccepted: int,
     *     fusions: int,
     *     activePlayers: int,
     * }
     */
    public function community(): array
    {
        return $this->cache->get(self::COMMUNITY_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::COMMUNITY_CACHE_TTL);
            $weekAgo = $this->clock->now()->modify('-7 days');

            return [
                'pulls' => array_map(static fn (BoosterOpeningCard $pull): array => [
                    'player' => $pull->getBoosterOpening()->getDiscordUser()->getUsername(),
                    'discordId' => $pull->getBoosterOpening()->getDiscordUser()->getDiscordId(),
                    'rarity' => $pull->getCard()->getRarity()->value,
                    'unique' => $pull->getCard()->isUnique(),
                    'holo' => $pull->getHoloQuantity() > 0,
                    'universe' => (string) $pull->getCard()->getExtension()?->getName(),
                    'slug' => (string) $pull->getCard()->getExtension()?->getSlug(),
                    'openedAt' => $pull->getBoosterOpening()->getOpenedAt(),
                ], $this->boosterOpeningCardRepository->findRecentNotablePulls($weekAgo, self::NOTABLE_PULLS)),
                'uniques' => $this->cardRepository->countPublishedUniques(),
                'holoCopies' => $this->userCardRepository->sumHoloCopies(),
                'tradesAccepted' => $this->tradeOfferRepository->countAccepted(),
                'fusions' => $this->fusionOperationRepository->sumFusions(),
                'activePlayers' => $this->boosterOpeningRepository->countDistinctPlayersSince($weekAgo),
            ];
        });
    }

    /**
     * Newest listings of the other players: visible in clear, like on /marche.
     *
     * @return list<MarketListing>
     */
    public function latestListings(DiscordUser $user): array
    {
        return $this->marketListingRepository->findActivePage(null, $user, null, null, null, 'date_desc', 1, self::LATEST_LISTINGS);
    }

    /**
     * @return array{cards: int, possible: int} cards with at least one fusion available, and the fusions they allow in total
     */
    private function fusions(DiscordUser $user): array
    {
        $reserved = $this->engagedCopies->reservedQuantities($user);
        $cards = 0;
        $possible = 0;

        foreach ($this->userCardRepository->findWithNormalCopies($user, FusionService::FUSION_COST) as $userCard) {
            $fusions = intdiv(FusionService::freeNormalCopies($userCard, $reserved[(string) $userCard->getCard()->getId()] ?? null), FusionService::FUSION_COST);
            if ($fusions > 0) {
                ++$cards;
                $possible += $fusions;
            }
        }

        return ['cards' => $cards, 'possible' => $possible];
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return array<string, array{drawn: int, total: int, amount: string}>
     */
    private function rewardProgress(DiscordUser $user, array $extensions): array
    {
        if ([] === $extensions || !$this->featureFlags->isEnabled(FeatureEnum::UNIVERSE_REWARDS)) {
            return [];
        }

        $ids = array_map(static fn (Extension $extension): string => (string) $extension->getId(), $extensions);
        $totals = $this->cardRepository->countPublishedNonUniqueByExtension($ids);
        $drawn = $this->userCardRepository->countDrawnOwnedNonUniqueByExtension($user, $ids);

        $progress = [];
        foreach ($extensions as $extension) {
            $id = (string) $extension->getId();
            $amount = $this->completionChecker->rewardAmount($extension);
            $total = $totals[$id] ?? 0;
            if ($amount <= 0 || 0 === $total || null !== $this->rewardRepository->findOneBy(['discordUser' => $user, 'extension' => $extension])) {
                continue;
            }
            $progress[$id] = ['drawn' => $drawn[$id] ?? 0, 'total' => $total, 'amount' => CoinAmount::fromCoins($amount)->format()];
        }

        return $progress;
    }
}
