<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Repository\BoosterRepository;
use App\Repository\CardRepository;
use App\Repository\ExtensionRepository;
use App\Repository\UniverseCompletionRewardRepository;
use App\Repository\UserBoosterRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\UniverseCompletionChecker;
use App\Service\Feature\FeatureFlags;
use App\Service\Wishlist\WishlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The universe pages: an index of the published extensions and one pokédex-style
 * page per extension (banners, personal completion, the full set with unowned
 * cards masked, related boosters, unique 1/1 drop status).
 */
class UniverseController extends AbstractController
{
    /**
     * Memo: ownedBoosterCounts serves both the visibility filter and the
     * template, one query instead of two.
     *
     * @var array<string, int>|null
     */
    private ?array $ownedBoosterCountsCache = null;

    public function __construct(
        private readonly ExtensionRepository $extensionRepository,
        private readonly BoosterRepository $boosterRepository,
        private readonly CardRepository $cardRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly UserBoosterRepository $userBoosterRepository,
        private readonly BoosterAvailabilityService $boosterAvailability,
        private readonly FeatureFlags $featureFlags,
        private readonly UniverseCompletionChecker $completionChecker,
        private readonly UniverseCompletionRewardRepository $rewardRepository,
        private readonly WishlistService $wishlist,
    ) {
    }

    public const string SORT_NEARLY_DONE = 'presque';

    public const string SORT_RECENT = 'recents';

    public const string SORT_ALPHA = 'az';

    #[Route('/univers', name: 'universes')]
    public function index(#[CurrentUser] DiscordUser $user, Request $request): Response
    {
        $sort = $request->query->getString('tri');
        $sort = \in_array($sort, [self::SORT_RECENT, self::SORT_ALPHA], true) ? $sort : self::SORT_NEARLY_DONE;
        $search = mb_substr(trim($request->query->getString('q')), 0, 60);

        $extensions = $this->extensionRepository->findPublishedWithPublishedCardCount();
        $ownedByExtension = $this->userCardRepository->countOwnedGroupedByExtension($user);
        $coverImages = $this->cardRepository->findCoverImageNamesByExtension();

        $universes = array_map(static function (array $item) use ($ownedByExtension, $coverImages): array {
            $extensionId = (string) $item['extension']->getId();
            $owned = min($ownedByExtension[$extensionId] ?? 0, $item['cardCount']);

            return [
                'extension' => $item['extension'],
                'total' => $item['cardCount'],
                'owned' => $owned,
                'percentage' => $item['cardCount'] > 0 ? (int) floor($owned / $item['cardCount'] * 100) : 0,
                'coverImage' => $coverImages[$extensionId] ?? null,
                'hasClaimableBooster' => $item['hasClaimableBooster'],
            ];
        }, $extensions);

        if ('' !== $search) {
            $universes = array_values(array_filter(
                $universes,
                static fn (array $universe): bool => str_contains(mb_strtolower((string) $universe['extension']->getName()), mb_strtolower($search)),
            ));
        }

        usort($universes, static fn (array $a, array $b): int => match ($sort) {
            self::SORT_RECENT => $b['extension']->getCreatedAt() <=> $a['extension']->getCreatedAt(),
            self::SORT_ALPHA => 0,
            default => [$b['percentage'], $b['owned']] <=> [$a['percentage'], $a['owned']],
        } ?: strcasecmp((string) $a['extension']->getName(), (string) $b['extension']->getName()));

        $completed = array_values(array_filter($universes, static fn (array $universe): bool => $universe['total'] > 0 && $universe['owned'] >= $universe['total']));
        $toComplete = array_values(array_filter($universes, static fn (array $universe): bool => !\in_array($universe, $completed, true)));

        $rewardsOn = $this->featureFlags->isEnabled(FeatureEnum::UNIVERSE_REWARDS);
        $upcoming = $this->extensionRepository->findUpcoming();

        return $this->render('pages/universes.html.twig', [
            'toComplete' => $toComplete,
            'completed' => $completed,
            'universeCount' => \count($extensions),
            'sort' => $sort,
            'search' => $search,
            // honest wording: a reward needs every card pulled by the player himself
            'rewardsOn' => $rewardsOn && array_any($extensions, fn (array $item): bool => $this->completionChecker->rewardAmount($item['extension']) > 0),
            'coinsEarned' => $rewardsOn ? $this->rewardRepository->sumPaidCoins($user) : 0,
            'upcoming' => '' === $search ? $upcoming : null,
            'upcomingCover' => $upcoming instanceof Extension ? $this->cardRepository->findTeaserCoverImageName($upcoming) : null,
        ]);
    }

    #[Route('/univers/{slug}', name: 'universes_show', requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(#[CurrentUser] DiscordUser $user, string $slug, Request $request): Response
    {
        $filter = $request->query->getString('filtre');
        $filter = \in_array($filter, ['possedees', 'manquantes'], true) ? $filter : 'toutes';

        $extension = $this->extensionRepository->findOneBy([
            'slug' => $slug,
            'status' => ExtensionStatusEnum::PUBLISHED,
        ]);

        if (!$extension instanceof Extension) {
            throw $this->createNotFoundException(\sprintf('Unknown universe "%s".', $slug));
        }

        $catalog = $this->sortRarestFirst($this->cardRepository->findPublishedByExtension($extension));

        // owned rows keyed by card id: the set grid shows owned cards, masks the rest
        $ownedByCardId = [];
        foreach ($this->userCardRepository->findOwnedWithCards($user, $extension) as $userCard) {
            $ownedByCardId[(string) $userCard->getCard()->getId()] = $userCard;
        }

        // drafts are out of the catalogue: they must not count toward the completion
        $ownedByCardId = array_intersect_key(
            $ownedByCardId,
            array_flip(array_map(static fn (Card $card): string => (string) $card->getId(), $catalog)),
        );

        $uniquesTotal = 0;
        $uniquesClaimed = 0;
        foreach ($catalog as $card) {
            if ($card->isUnique()) {
                ++$uniquesTotal;
                $uniquesClaimed += $card->isClaimed() ? 1 : 0;
            }
        }

        return $this->render('pages/universe.html.twig', [
            'extension' => $extension,
            'catalog' => $catalog,
            'filter' => $filter,
            'ownedByCardId' => $ownedByCardId,
            'boosters' => $this->visibleBoosters($extension, $user),
            'ownedBoosterCounts' => $this->ownedBoosterCounts($user),
            'wishlistEnabled' => $this->wishlist->isEnabled(),
            'wishedIds' => $this->wishlist->wishedCardIds($user),
            'watching' => $this->wishlist->isEnabled() && $this->wishlist->isWatching($user, $extension),
            'uniquesTotal' => $uniquesTotal,
            'uniquesClaimed' => $uniquesClaimed,
            'reward' => $this->rewardProgress($user, $extension, $catalog),
            'coverImage' => $this->cardRepository->findCoverImageNamesByExtension()[(string) $extension->getId()] ?? null,
        ]);
    }

    /**
     * Reward progress counts only the cards the player pulled himself; null while the feature is off.
     *
     * @param list<Card> $catalog
     *
     * @return array{drawn: int, total: int, amount: string, rewarded: bool}|null
     */
    private function rewardProgress(DiscordUser $user, Extension $extension, array $catalog): ?array
    {
        $amount = $this->completionChecker->rewardAmount($extension);
        $total = \count(array_filter($catalog, static fn (Card $card): bool => !$card->isUnique()));
        if (!$this->featureFlags->isEnabled(FeatureEnum::UNIVERSE_REWARDS) || $amount <= 0 || 0 === $total) {
            return null;
        }

        return [
            'drawn' => $this->userCardRepository->countDrawnOwnedNonUniqueByExtension($user, [(string) $extension->getId()])[(string) $extension->getId()] ?? 0,
            'total' => $total,
            'amount' => CoinAmount::fromCoins($amount)->format(),
            'rewarded' => null !== $this->rewardRepository->findOneBy(['discordUser' => $user, 'extension' => $extension]),
        ];
    }

    /**
     * Same visibility rule as the hub, via BoosterAvailabilityService:
     * claimable boosters, plus non-claimable ones (event / code) the user
     * actually owns copies of.
     *
     * @return list<Booster>
     */
    private function visibleBoosters(Extension $extension, DiscordUser $user): array
    {
        return $this->boosterAvailability->filterVisible(
            $this->boosterRepository->findBy(['extension' => $extension], ['name' => 'ASC', 'id' => 'ASC']),
            $this->ownedBoosterCounts($user),
        );
    }

    /**
     * @return array<string, int> booster id => owned quantity
     */
    private function ownedBoosterCounts(DiscordUser $user): array
    {
        if (null !== $this->ownedBoosterCountsCache) {
            return $this->ownedBoosterCountsCache;
        }

        $counts = [];
        foreach ($this->userBoosterRepository->findBy(['discordUser' => $user]) as $userBooster) {
            $counts[(string) $userBooster->getBooster()->getId()] = $userBooster->getQuantity();
        }

        return $this->ownedBoosterCountsCache = $counts;
    }

    /**
     * Pokédex order: rarest first, name as tiebreak — the same ranking as the
     * opening aside, so the set reads in the same order everywhere.
     *
     * @param list<Card> $cards
     *
     * @return list<Card>
     */
    private function sortRarestFirst(array $cards): array
    {
        usort(
            $cards,
            static fn (Card $a, Card $b): int => CardRarityEnum::compareRarestFirst($a->getRarity(), $b->getRarity())
                ?: $a->getName() <=> $b->getName(),
        );

        return $cards;
    }
}
