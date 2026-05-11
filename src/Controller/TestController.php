<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TestController extends AbstractController
{
    #[Route('/test/card-borders', name: 'test_card_borders')]
    public function cardBorders(CardRepository $cardRepository): Response
    {
        // Get a random card for testing
        $card = $cardRepository->findOneBy(['status' => CardStatusEnum::PUBLISHED]);

        return $this->render('test/card-borders.html.twig', [
            'card' => $card,
        ]);
    }
}
