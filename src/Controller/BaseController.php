<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class BaseController extends AbstractController
{
    #[Route('/', name: 'homepage')]
    public function homepage(CardRepository $cardRepository, CacheInterface $cache): Response
    {
        $cardsIds = $cache->get('daycards', function (ItemInterface $item) use ($cardRepository): array {
            $item->expiresAt(new \DateTime('tomorrow'));

            return $cardRepository->findRandomCardId(3);
        });

        $cards = $cardRepository->findBy(['id' => $cardsIds]);

        return $this->render('pages/homepage.html.twig', [
            'cards' => $cards,
        ]);
    }

    #[Route('/extensions', name: 'extensions')]
    public function extensions(): Response
    {
        return $this->render('pages/coming_soon.html.twig');
    }
}
