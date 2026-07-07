<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Entity\CardStatusEnum;
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
    /**
     * The homepage teases the LATEST universes only (magazine layout: 3 tiles
     * + the "coming soon" teaser); the full list lives on /univers.
     */
    private const int FEATURED_UNIVERSES = 3;

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

        // the status filter makes an unpublished (not just deleted) cached card
        // trip the count check below instead of staying on display all day
        $cardsIds = $cache->get('daycards', $pickDayCards);
        $cards = $cardRepository->findBy(['id' => $cardsIds, 'status' => CardStatusEnum::PUBLISHED]);

        // Stale cache (a cached card got unpublished or deleted): redraw.
        if (\count($cards) !== \count($cardsIds)) {
            $cache->delete('daycards');
            $cardsIds = $cache->get('daycards', $pickDayCards);
            $cards = $cardRepository->findBy(['id' => $cardsIds, 'status' => CardStatusEnum::PUBLISHED]);
        }

        $extensions = $extensionRepository->findPublishedWithPublishedCardCount();
        $coverImages = $cardRepository->findCoverImageNamesByExtension();

        // the list is createdAt ASC: the featured universes are the tail, newest first
        $featured = array_map(static fn (array $item): array => [
            ...$item,
            'coverImage' => $coverImages[(string) $item['extension']->getId()] ?? null,
        ], array_reverse(\array_slice($extensions, -self::FEATURED_UNIVERSES)));

        return $this->render('pages/homepage.html.twig', [
            'cards' => $cards,
            'universes' => $featured,
            'universesTotal' => \count($extensions),
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
