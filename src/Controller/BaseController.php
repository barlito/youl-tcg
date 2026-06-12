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
        $cardsIds = $cache->get('daycards', function (ItemInterface $item) use ($cardRepository): array {
            $item->expiresAt(new \DateTime('tomorrow'));

            return $cardRepository->findRandomCardId(3);
        });

        $cards = $cardRepository->findBy(['id' => $cardsIds]);

        $packsOpenedCount = $cache->get('home_packs_opened', function (ItemInterface $item) use ($boosterOpeningRepository): int {
            $item->expiresAfter(300);

            return $boosterOpeningRepository->countAll();
        });

        $extensions = $extensionRepository->findPublishedWithPublishedCardCount();

        return $this->render('pages/homepage.html.twig', [
            'cards' => $cards,
            'extensions' => $extensions,
            'cardsTotal' => array_sum(array_column($extensions, 'cardCount')),
            'packsOpenedCount' => $packsOpenedCount,
        ]);
    }

    #[Route('/extensions', name: 'extensions')]
    public function extensions(): Response
    {
        return $this->render('pages/coming_soon.html.twig');
    }

    #[Route('/boosters', name: 'boosters')]
    public function boosters(): Response
    {
        return $this->render('pages/coming_soon.html.twig');
    }
}
