<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProfileUniverseComparison;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Repository\CardRepository;
use App\Service\Booster\BoosterClaimQuotaInterface;
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
 * profiles use: same states, same grid, one service.
 */
class CollectionController extends AbstractController
{
    public function __construct(
        private readonly CardRepository $cardRepository,
        private readonly ProfileComparisonService $profileComparisonService,
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
            return $this->redirectToRoute(
                'collection',
                ['slug' => $this->resolveLegacyExtensionId($legacyId, $comparison->universes)->getSlug()],
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $currentExtension = $this->resolveExtensionFilter($slug, $comparison->universes);
        $visibleUniverses = $this->filterUniverses($comparison->universes, $currentExtension);

        // fond des tuiles du carrousel quand l'extension n'a pas d'image uploadée
        $coverImages = $this->cardRepository->findCoverImageNamesByExtension();

        $strip = array_map(static fn (ProfileUniverseComparison $universe): array => [
            'extension' => $universe->extension,
            'total' => $universe->total,
            'owned' => $universe->common,
            'percentage' => $universe->total > 0 ? (int) round($universe->common / $universe->total * 100) : 0,
            'coverImage' => $coverImages[(string) $universe->extension->getId()] ?? null,
        ], $comparison->universes);

        return $this->render('pages/collection.html.twig', [
            'entry' => $this->leaderboardService->getEntryFor($user),
            'universes' => $strip,
            'visibleUniverses' => $visibleUniverses,
            'currentExtension' => $currentExtension,
            'ownedTotalDistinct' => $comparison->common,
            'totalPublished' => $comparison->total,
            'completionPct' => $comparison->total > 0 ? (int) round($comparison->common / $comparison->total * 100) : 0,
            // filter chip counters follow the grid actually rendered, not the catalogue
            'visibleTotal' => array_sum(array_map(static fn (ProfileUniverseComparison $u): int => $u->total, $visibleUniverses)),
            'visibleOwned' => array_sum(array_map(static fn (ProfileUniverseComparison $u): int => $u->common, $visibleUniverses)),
            'visibleMissing' => array_sum(array_map(static fn (ProfileUniverseComparison $u): int => $u->missingBoth, $visibleUniverses)),
            'remainingClaims' => $this->boosterClaimQuota->getRemainingClaims($user),
            'dailyLimit' => BoosterClaimQuotaInterface::DAILY_LIMIT,
        ]);
    }

    /**
     * @param list<ProfileUniverseComparison> $universes
     *
     * @return list<ProfileUniverseComparison>
     */
    private function filterUniverses(array $universes, ?Extension $currentExtension): array
    {
        if (!$currentExtension instanceof Extension) {
            return $universes;
        }

        return array_values(array_filter(
            $universes,
            static fn (ProfileUniverseComparison $universe): bool => $universe->extension->getId() === $currentExtension->getId(),
        ));
    }

    /**
     * @param list<ProfileUniverseComparison> $universes
     */
    private function resolveLegacyExtensionId(string $id, array $universes): Extension
    {
        foreach ($universes as $universe) {
            if (((string) $universe->extension->getId()) === $id) {
                return $universe->extension;
            }
        }

        throw $this->createNotFoundException(\sprintf('Unknown extension "%s".', $id));
    }

    /**
     * Resolves the {slug} route parameter against the compared universes already
     * built: no extra Doctrine lookup, and an unknown slug is a plain 404.
     *
     * @param list<ProfileUniverseComparison> $universes
     */
    private function resolveExtensionFilter(?string $slug, array $universes): ?Extension
    {
        if (null === $slug || '' === $slug) {
            return null;
        }

        foreach ($universes as $universe) {
            if ($universe->extension->getSlug() === $slug) {
                return $universe->extension;
            }
        }

        throw $this->createNotFoundException(\sprintf('Unknown extension "%s".', $slug));
    }
}
