<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Repository\BoosterOpeningRepository;
use App\Service\Booster\OpeningLuckStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class OpeningHistoryController extends AbstractController
{
    /**
     * Openings per page: 2 free claims a day means a page holds ~a week of
     * regular play while staying light to render (each entry draws its cards).
     */
    private const int PER_PAGE = 12;

    #[Route('/mes-ouvertures', name: 'opening_history')]
    public function __invoke(
        Request $request,
        #[CurrentUser] DiscordUser $user,
        BoosterOpeningRepository $boosterOpeningRepository,
        OpeningLuckStatsProvider $openingLuckStatsProvider,
    ): Response {
        $page = $request->query->getInt('page', 1);
        $total = $boosterOpeningRepository->countByUser($user);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        if ($page < 1 || $page > $lastPage) {
            throw $this->createNotFoundException(\sprintf('Page %d is out of range.', $page));
        }

        return $this->render('pages/opening_history.html.twig', [
            'openings' => $boosterOpeningRepository->findHistoryPage($user, $page, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'stats' => $openingLuckStatsProvider->getStats($user),
        ]);
    }
}
