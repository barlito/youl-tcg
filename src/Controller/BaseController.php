<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BoosterOpeningRepository;
use App\Repository\CardRepository;
use App\Repository\ExtensionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class BaseController extends AbstractController
{
    #[Route('/', name: 'homepage')]
    public function homepage(
        CardRepository $cardRepository,
        ExtensionRepository $extensionRepository,
        BoosterOpeningRepository $boosterOpeningRepository,
        CacheInterface $cache,
    ): Response {
        $pickDayCards = function (ItemInterface $item) use ($cardRepository): array {
            $item->expiresAt(new \DateTime('tomorrow'));

            return $cardRepository->findRandomCardId(3);
        };

        $cardsIds = $cache->get('daycards', $pickDayCards);
        $cards = $cardRepository->findBy(['id' => $cardsIds]);

        // Stale cache (a cached card got unpublished or deleted): redraw.
        if (\count($cards) !== \count($cardsIds)) {
            $cache->delete('daycards');
            $cardsIds = $cache->get('daycards', $pickDayCards);
            $cards = $cardRepository->findBy(['id' => $cardsIds]);
        }

        $extensions = $extensionRepository->findPublishedWithPublishedCardCount();

        return $this->render('pages/homepage.html.twig', [
            'cards' => $cards,
            'extensions' => $extensions,
            'cardsTotal' => array_sum(array_column($extensions, 'cardCount')),
            'packsOpenedCount' => $boosterOpeningRepository->countAll(),
        ]);
    }

    /**
     * Legacy "coming soon" url: the universe pages live at /univers now.
     */
    #[Route('/extensions', name: 'extensions')]
    public function extensions(): Response
    {
        return $this->redirectToRoute('universes', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/boosters', name: 'boosters')]
    public function boosters(): Response
    {
        return $this->render('pages/boosters.html.twig');
    }
}
