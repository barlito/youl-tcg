<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProfileComparison;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Service\Booster\BoosterClaimQuotaInterface;
use App\Service\Collection\CompletionStripBuilder;
use App\Service\Leaderboard\LeaderboardService;
use App\Service\Leaderboard\ProfileComparisonService;
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

        return $this->render('pages/collection.html.twig', [
            'entry' => $this->leaderboardService->getEntryFor($user),
            'comparison' => $comparison,
            // grid and filter chips follow the extension actually rendered
            'visible' => $this->profileComparisonService->restrictTo($comparison, $currentExtension),
            'strip' => $this->stripBuilder->build($comparison->universes),
            'currentExtension' => $currentExtension,
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
