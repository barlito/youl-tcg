<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TestController extends AbstractController
{
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/test/card-borders', name: 'test_card_borders')]
    public function cardBorders(CardRepository $cardRepository): Response
    {
        if ('prod' === $this->getParameter('kernel.environment')) {
            throw $this->createNotFoundException();
        }

        $card = $cardRepository->findOneBy(['status' => CardStatusEnum::PUBLISHED]);

        return $this->render('test/card-borders.html.twig', [
            'card' => $card,
        ]);
    }
}
