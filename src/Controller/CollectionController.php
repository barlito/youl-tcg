<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProfileComparison;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\FeatureEnum;
use App\Service\Booster\BoosterClaimQuotaInterface;
use App\Service\Collection\CollectionTagFilter;
use App\Service\Collection\CompletionStripBuilder;
use App\Service\Feature\FeatureFlags;
use App\Service\Leaderboard\LeaderboardService;
use App\Service\Leaderboard\ProfileComparisonService;
use App\Service\Wishlist\WishlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * « Ma collection »: the player's own pokédex — the whole published catalogue
 * split by universe, the cards not owned yet face down. It also carries the
 * profile stats (rank, holos, uniques, opened packs), so a player never needs
 * to visit their own /joueur/{id} page, which redirects here.
 *
 * The comparison of the user with themselves is the same one the public
 * profiles use: same states, same grid, same strip, one service.
 */
class CollectionController extends AbstractController
{
    public function __construct(
        private readonly ProfileComparisonService $profileComparisonService,
        private readonly CompletionStripBuilder $stripBuilder,
        private readonly LeaderboardService $leaderboardService,
        private readonly BoosterClaimQuotaInterface $boosterClaimQuota,
        private readonly WishlistService $wishlist,
        private readonly CollectionTagFilter $tagFilter,
        private readonly FeatureFlags $featureFlags,
    ) {
    }

    #[Route('/collection/{slug}', name: 'collection', requirements: ['slug' => '[a-z0-9-]+'], defaults: ['slug' => null])]
    public function __invoke(
        Request $request,
        #[CurrentUser] DiscordUser $user,
        ?string $slug = null,
    ): Response {
        $comparison = $this->profileComparisonService->compare($user, $user);

        // Legacy pre-slug urls (/collection?extension=<uuid>): redirect to the slug
        // route instead of silently ignoring the filter; unknown id stays a 404,
        // the contract the query-param version already had.
        $legacyId = $request->query->getString('extension');
        if (null === $slug && '' !== $legacyId) {
            $legacyExtension = $comparison->extensionById($legacyId);

            if (!$legacyExtension instanceof Extension) {
                throw $this->createNotFoundException(\sprintf('Unknown extension "%s".', $legacyId));
            }

            return $this->redirectToRoute(
                'collection',
                ['slug' => $legacyExtension->getSlug()],
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $currentExtension = $this->resolveExtensionFilter($comparison, $slug);
        // grid and filter chips follow the extension actually rendered
        $visible = $this->profileComparisonService->restrictTo($comparison, $currentExtension);

        // tags are a duel feature: the chips follow the universe shown, the tag narrows the owned cards
        $duelEnabled = $this->featureFlags->isEnabled(FeatureEnum::DUEL);
        $tagGroups = $duelEnabled ? $this->tagFilter->groups($visible) : [];
        $currentTag = $duelEnabled ? $this->tagFilter->parse($request->query->getString('tag')) : null;
        if (null !== $currentTag) {
            $visible = $this->profileComparisonService->restrictToOwnedTag($visible, $currentTag);
        }

        return $this->render('pages/collection.html.twig', [
            'entry' => $this->leaderboardService->getEntryFor($user),
            'comparison' => $comparison,
            'visible' => $visible,
            'strip' => $this->stripBuilder->build($comparison->universes),
            'currentExtension' => $currentExtension,
            'duelEnabled' => $duelEnabled,
            'tagGroups' => $tagGroups,
            'currentTag' => $currentTag,
            'wishlistEnabled' => $this->wishlist->isEnabled(),
            'wishedIds' => $this->wishlist->wishedCardIds($user),
            'remainingClaims' => $this->boosterClaimQuota->getRemainingClaims($user),
            'dailyLimit' => BoosterClaimQuotaInterface::DAILY_LIMIT,
        ]);
    }

    private function resolveExtensionFilter(ProfileComparison $comparison, ?string $slug): ?Extension
    {
        if (null === $slug || '' === $slug) {
            return null;
        }

        return $comparison->extensionBySlug($slug)
            ?? throw $this->createNotFoundException(\sprintf('Unknown extension "%s".', $slug));
    }
}
