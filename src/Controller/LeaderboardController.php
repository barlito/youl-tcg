<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Repository\DiscordUserRepository;
use App\Repository\UserCardRepository;
use App\Service\Leaderboard\LeaderboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The collectors leaderboard and the public player profiles. A profile only
 * reveals a card the visitor ALSO owns; everything else is masked (card back,
 * no name anywhere in the DOM) — the 1/1 mystery included.
 */
class LeaderboardController extends AbstractController
{
    public function __construct(
        private readonly LeaderboardService $leaderboardService,
        private readonly DiscordUserRepository $discordUserRepository,
        private readonly UserCardRepository $userCardRepository,
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

        $isSelf = $visitor->getDiscordId() === $profile->getDiscordId();
        // cards the VISITOR owns: the reveal key of the masking rule
        $visitorCardIds = $isSelf ? [] : array_flip($this->userCardRepository->findOwnedCardIds($visitor));

        $universes = [];
        $hiddenTotal = 0;
        foreach ($this->userCardRepository->findOwnedWithCards($profile) as $userCard) {
            $extension = $userCard->getCard()->getExtension();
            if (!$extension instanceof Extension) {
                continue;
            }
            $extensionId = (string) $extension->getId();
            $visible = $isSelf || isset($visitorCardIds[(string) $userCard->getCard()->getId()]);

            $universes[$extensionId] ??= ['extension' => $extension, 'cards' => [], 'hiddenCount' => 0];
            $universes[$extensionId]['cards'][] = ['userCard' => $userCard, 'visible' => $visible];
            $universes[$extensionId]['hiddenCount'] += $visible ? 0 : 1;
            $hiddenTotal += $visible ? 0 : 1;
        }

        /** @var list<array{extension: Extension, cards: list<array{userCard: UserCard, visible: bool}>, hiddenCount: int}> $universes */
        $universes = array_values($universes);
        usort($universes, static fn (array $a, array $b): int => strcasecmp($a['extension']->getName(), $b['extension']->getName()));

        return $this->render('pages/player_profile.html.twig', [
            'profile' => $profile,
            'entry' => $this->leaderboardService->getEntryFor($profile),
            'universes' => $universes,
            'hiddenTotal' => $hiddenTotal,
            'isSelf' => $isSelf,
        ]);
    }
}
