<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Repository\DiscordUserRepository;
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

    #[Route('/joueur/{discordId}', name: 'leaderboard_player', requirements: ['discordId' => '\d+'])]
    public function player(#[CurrentUser] DiscordUser $visitor, string $discordId): Response
    {
        $profile = $this->discordUserRepository->find($discordId);

        if (!$profile instanceof DiscordUser) {
            throw $this->createNotFoundException(\sprintf('Unknown player "%s".', $discordId));
        }

        return $this->render('pages/player_profile.html.twig', [
            'profile' => $profile,
            'entry' => $this->leaderboardService->getEntryFor($profile),
            'comparison' => $this->profileComparisonService->compare($profile, $visitor),
        ]);
    }
}
