<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProfileComparison;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Repository\DiscordUserRepository;
use App\Service\Collection\CompletionStripBuilder;
use App\Service\Leaderboard\LeaderboardService;
use App\Service\Leaderboard\ProfileComparisonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The collectors leaderboard and the public player profiles. A profile shows
 * the whole published catalogue compared with the visitor's collection; the
 * disclosure rules live in ProfileComparisonService.
 */
class LeaderboardController extends AbstractController
{
    public function __construct(
        private readonly LeaderboardService $leaderboardService,
        private readonly DiscordUserRepository $discordUserRepository,
        private readonly ProfileComparisonService $profileComparisonService,
        private readonly CompletionStripBuilder $stripBuilder,
    ) {
    }

    #[Route('/classement', name: 'leaderboard')]
    public function index(#[CurrentUser] DiscordUser $user): Response
    {
        return $this->render('pages/leaderboard.html.twig', [
            'entries' => $this->leaderboardService->getLeaderboard(),
            'currentUser' => $user,
        ]);
    }

    #[Route(
        '/joueur/{discordId}/{slug}',
        name: 'leaderboard_player',
        requirements: ['discordId' => '\d+', 'slug' => '[a-z0-9-]+'],
        defaults: ['slug' => null],
    )]
    public function player(#[CurrentUser] DiscordUser $visitor, string $discordId, ?string $slug = null): Response
    {
        // your own profile IS your collection page: one page, not two
        if ($visitor->getDiscordId() === $discordId) {
            return $this->redirectToRoute('collection', ['slug' => $slug]);
        }

        $profile = $this->discordUserRepository->find($discordId);

        if (!$profile instanceof DiscordUser) {
            throw $this->createNotFoundException(\sprintf('Unknown player "%s".', $discordId));
        }

        $comparison = $this->profileComparisonService->compare($profile, $visitor);
        $currentExtension = $this->resolveExtensionFilter($comparison, $slug);

        return $this->render('pages/player_profile.html.twig', [
            'profile' => $profile,
            'entry' => $this->leaderboardService->getEntryFor($profile),
            'comparison' => $comparison,
            // grid and filter chips follow the extension actually rendered
            'visible' => $this->profileComparisonService->restrictTo($comparison, $currentExtension),
            'strip' => $this->stripBuilder->build($comparison->universes),
            'currentExtension' => $currentExtension,
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
